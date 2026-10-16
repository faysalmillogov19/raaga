<?php

namespace App\Http\Controllers\Admin;

use App\Enums\Droit;
use App\Http\Controllers\Controller;
use App\Models\CampagneBoutiques;
use App\Models\CorrectionPaiement;
use App\Models\DecisionAvance;
use App\Models\ImportValidation;
use App\Models\MoyenPaiement;
use App\Models\Niveau;
use App\Models\Remboursement;
use App\Models\Site;
use App\Models\TypeBoutique;
use App\Models\Unite;
use App\Models\VerificationKyc;
use App\Models\ZoneSite;
use App\Support\AValider;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Throwable;

/**
 * « À valider » (VALIDATEUR) : file regroupée par type d'élément, panneau de détail, décision sur une ligne ou sur une
 * sélection. Règles : jamais de décision sur un élément que l'on a soumis soi-même ; campagnes, démarches du site public
 * et données financières jamais en lot (422) ; traitement élément par élément, résultat partiel sans tout annuler ;
 * un élément déjà traité par quelqu'un d'autre est signalé. Chaque décision passe par l'action existante de l'élément
 * (mêmes droits, mêmes règles, même journal d'audit, même notification de l'auteur).
 */
class AValiderController extends Controller
{
    /** Précisions rendues par l'action d'un élément (résultat site par site d'un import). @var list<string> */
    private array $complements = [];

    /** Vue d'ensemble (toutes les rubriques) ou page d'une rubrique (« sites », « finances-sites »…). */
    public function index(Request $request, ?string $rubrique = null): View
    {
        Gate::authorize(Droit::AValiderVoir->value);
        $user = $request->user();
        $toutes = AValider::pour($user);
        $groupe = $rubrique === null ? null : (AValider::groupeDuSlug($rubrique) ?? abort(404));
        abort_if($groupe !== null && ! array_key_exists($groupe, $toutes), 403);
        $groupes = $groupe === null ? $toutes : [$groupe => $toutes[$groupe]];
        $element = is_string($request->query('detail')) ? collect($groupes)->flatten(1)->firstWhere('cle', $request->query('detail')) : null;

        return view('admin.a-valider', [
            'groupes' => $groupes,
            'rubrique' => $groupe,
            // Rubrique vide : lien vers la rubrique suivante qui a des éléments.
            'rubriqueSuivante' => $groupe !== null && $toutes[$groupe] === [] ? $this->rubriqueSuivante($toutes, $groupe) : null,
            'detail' => $element ? AValider::detail($user, $element) : null,
            // Élément suivant : dans la même rubrique (ou dans toute la file sur la vue d'ensemble).
            'suivant' => $element ? $this->suivant($groupes, $element['cle']) : null,
        ]);
    }

    public function decider(Request $request): RedirectResponse
    {
        Gate::authorize(Droit::AValiderVoir->value);
        $donnees = $request->validate([
            'elements' => ['required', 'array', 'min:1', 'max:200'],
            'elements.*' => ['string', 'regex:/^(site|grille|finances|type|niveaux|zones|unites|moyens|investissement|delais|campagne|phase|autorisation|import|correction|remboursement|annulation|avance):\d+$/'],
            'decision' => ['required', 'in:confirmer,rejeter'],
            'motif' => ['nullable', 'required_if:decision,rejeter', 'string', 'min:5', 'max:1000'],
            'mode' => ['nullable', 'in:ligne,lot'],
            'suivant' => ['nullable', 'string', 'max:40'],
            'rubrique' => ['nullable', 'string', 'max:40'],
        ], ['elements.required' => __('admin.a_valider.erreurs.selection'), 'motif.*' => __('admin.utilisateurs.erreurs.motif')]);
        $elements = array_values(array_unique($donnees['elements']));

        // Éléments sensibles : jamais de décision groupée, même par une requête fabriquée.
        $lot = ($donnees['mode'] ?? 'lot') === 'lot' || count($elements) > 1;
        $sensibles = array_filter($elements, fn (string $cle) => in_array(strtok($cle, ':'), AValider::nonGroupables(), true));
        abort_if($lot && $sensibles !== [], 422, __('admin.a_valider.erreurs.non_groupable'));

        $user = $request->user();
        $reussis = 0;
        $refus = [];
        foreach ($elements as $cle) {
            $element = AValider::trouver($user, $cle);
            $erreur = match (true) {
                $element === null => __('admin.a_valider.erreurs.deja_traite'),
                $element['moi'] => __('admin.a_valider.erreurs.auto_validation'),
                default => $this->appliquer($request, $cle, $donnees['decision'], $donnees['motif'] ?? null),
            };
            if ($erreur !== null) {
                $refus[] = ($element['libelle'] ?? $this->libelle($cle)).' ('.$erreur.')';

                continue;
            }
            $reussis++;
        }

        $message = trim($this->resultat($donnees['decision'], $reussis, $refus).' '.implode(' ', $this->complements));
        $suivant = isset($donnees['suivant']) && ($s = AValider::trouver($user, $donnees['suivant'])) && ! $s['moi'] ? $s['cle'] : null;
        // Retour sur la page d'où vient la décision (vue d'ensemble ou rubrique), sur l'élément suivant s'il y en a un.
        $rubrique = isset($donnees['rubrique']) && AValider::groupeDuSlug($donnees['rubrique']) ? $donnees['rubrique'] : null;
        $parametres = array_filter(['rubrique' => $rubrique, 'detail' => $suivant]);
        $retour = redirect()->route($rubrique ? 'admin.a-valider.rubrique' : 'admin.a-valider', $parametres);

        return $refus ? $retour->with('erreur', $message) : $retour->with('succes', $message);
    }

    /** Nom d'un élément qui n'est plus dans la file (déjà traité) : lu dans la base, à défaut sa clé. */
    private function libelle(string $cle): string
    {
        [$type, $id] = explode(':', $cle);
        $modele = match ($type) {
            'site', 'grille', 'finances', 'autorisation' => Site::query()->find($id),
            'type' => TypeBoutique::query()->find($id),
            'niveaux' => Niveau::query()->find($id),
            'zones' => ZoneSite::query()->find($id),
            'unites' => Unite::query()->find($id),
            'moyens' => MoyenPaiement::query()->find($id),
            'campagne' => CampagneBoutiques::query()->find($id),
            default => null,
        };

        return match (true) {
            $modele === null => $cle,
            $modele instanceof CampagneBoutiques => $modele->nom,
            $modele instanceof Site => ($modele->code ? $modele->code.' · ' : '').$modele->nom,
            default => $modele->code.' · '.$modele->libelle,
        };
    }

    /** « 8 validés, 1 refusé : SAR · Marché de Sarfalao (déjà traité). » */
    private function resultat(string $decision, int $reussis, array $refus): string
    {
        $parties = [];
        if ($reussis > 0 || $refus === []) {
            $parties[] = trans_choice('admin.a_valider.resultat.'.$decision, $reussis, ['n' => $reussis]);
        }
        if ($refus !== []) {
            $parties[] = trans_choice('admin.a_valider.resultat.refuses', count($refus), ['n' => count($refus)]).' : '.implode(' ; ', $refus);
        }

        return implode(', ', $parties).'.';
    }

    /** Première rubrique, après celle-ci (dans l'ordre du menu, en boucle), qui a des éléments à décider. */
    private function rubriqueSuivante(array $toutes, string $groupe): ?string
    {
        $ordre = array_keys($toutes);
        $i = array_search($groupe, $ordre, true);
        $suite = [...array_slice($ordre, $i + 1), ...array_slice($ordre, 0, $i)];

        return collect($suite)->first(fn (string $g) => collect($toutes[$g])->where('moi', false)->isNotEmpty());
    }

    /** Élément suivant de la file (ordre d'affichage) sur lequel l'utilisateur peut décider. */
    private function suivant(array $groupes, string $cle): ?string
    {
        $cles = collect($groupes)->flatten(1)->where('moi', false)->pluck('cle')->values();
        $i = $cles->search($cle);

        return $i === false ? null : $cles->get($i + 1);
    }

    /** Applique la décision par l'action existante de l'élément ; rend le message d'erreur, ou null en cas de succès. */
    private function appliquer(Request $request, string $cle, string $decision, ?string $motif): ?string
    {
        [$type, $id] = explode(':', $cle);
        $id = (int) $id;
        $session = $request->session();
        $session->forget(['succes', 'erreur']);

        $sousRequete = Request::create($request->url(), 'POST', ['motif' => $motif]);
        $sousRequete->setUserResolver(fn () => $request->user());
        $sousRequete->setLaravelSession($session);

        try {
            match ($type) {
                'site' => app(SitesController::class)->{$decision}($sousRequete, Site::query()->findOrFail($id)),
                'grille' => app(GrillesTarifairesController::class)->{$decision}($sousRequete, Site::query()->findOrFail($id)),
                'finances' => app(SiteFinancesController::class)->{$decision}($sousRequete, Site::query()->findOrFail($id)),
                'investissement' => app(ParametresInvestissementController::class)->{$decision}($sousRequete),
                'delais' => app(ParametresPaiementsController::class)->{$decision}($sousRequete),
                'type' => app(TypesBoutiqueController::class)->{$decision}($sousRequete, TypeBoutique::query()->findOrFail($id)),
                'niveaux', 'zones', 'unites', 'moyens' => app(NiveauxZonesController::class)->{$decision}($sousRequete, $type, $id),
                'campagne' => app(CampagnesBoutiquesController::class)->{$decision}($sousRequete, CampagneBoutiques::query()->findOrFail($id)),
                'phase' => app(PhaseController::class)->{$decision}($sousRequete, $id),
                'import' => app(ImportsValidationController::class)->{$decision}($sousRequete, ImportValidation::query()->findOrFail($id)),
                'correction' => app(PaiementsController::class)->{$decision.'Correction'}($sousRequete, CorrectionPaiement::query()->findOrFail($id)),
                'remboursement' => app(RemboursementsController::class)->{$decision}($sousRequete, Remboursement::query()->findOrFail($id)),
                'avance' => app(AvancesRetardController::class)->{$decision}($sousRequete, DecisionAvance::query()->findOrFail($id)),
                'annulation' => app(VerificationsIdentiteController::class)->{$decision.'Annulation'}($sousRequete, VerificationKyc::query()->findOrFail($id)),
                // Demande d'autorisation de modifier un site validé : acceptée (motif de la demande) ou refusée (motif saisi).
                'autorisation' => $decision === 'confirmer'
                    ? app(SitesController::class)->reviser($this->avecMotif($sousRequete, (string) Site::query()->findOrFail($id)->revision_demande_motif), Site::query()->findOrFail($id))
                    : app(SitesController::class)->refuserRevision($sousRequete, Site::query()->findOrFail($id)),
            };
        } catch (ValidationException $e) {
            return collect($e->errors())->flatten()->first();
        } catch (Throwable $e) {
            report($e);

            return __('admin.a_valider.erreurs.element', ['cle' => $cle]);
        }

        $erreur = $session->get('erreur');
        // Import entier : le résultat site par site (« 2 confirmés, 1 déjà traité ») complète le message.
        if ($type === 'import' && $erreur === null && $session->has('succes')) {
            $this->complements[] = $session->get('succes');
        }
        $session->forget(['succes', 'erreur']);

        return $erreur;
    }

    private function avecMotif(Request $requete, string $motif): Request
    {
        $requete->merge(['motif' => mb_strlen($motif) >= 5 ? $motif : __('admin.a_valider.motif_autorisation')]);

        return $requete;
    }
}
