<?php

namespace App\Http\Controllers\Admin;

use App\Enums\Droit;
use App\Http\Controllers\Controller;
use App\Models\CampagneBoutiques;
use App\Models\Site;
use App\Models\TypeBoutique;
use App\Support\AValider;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Throwable;

/**
 * « À valider » (VALIDATEUR) : file regroupée par type d'élément, décision sur une ligne ou sur une sélection.
 * Chaque décision passe par l'action existante de l'élément (mêmes droits, mêmes règles, même journal d'audit) ;
 * un rejet groupé applique un motif commun, enregistré pour chaque élément.
 */
class AValiderController extends Controller
{
    public function index(Request $request): View
    {
        Gate::authorize(Droit::AValiderVoir->value);

        return view('admin.a-valider', [
            'groupes' => array_intersect_key(AValider::pour($request->user()), array_flip(AValider::groupesVisibles($request->user()))),
        ]);
    }

    public function decider(Request $request): RedirectResponse
    {
        Gate::authorize(Droit::AValiderVoir->value);
        $donnees = $request->validate([
            'elements' => ['required', 'array', 'min:1', 'max:200'],
            'elements.*' => ['string', 'regex:/^(site|grille|finances|type|niveaux|zones|unites|campagne|phase|autorisation):\d+$/'],
            'decision' => ['required', 'in:confirmer,rejeter'],
            'motif' => ['nullable', 'required_if:decision,rejeter', 'string', 'min:5', 'max:1000'],
        ], ['elements.required' => __('admin.a_valider.erreurs.selection'), 'motif.*' => __('admin.utilisateurs.erreurs.motif')]);

        $reussis = 0;
        $echecs = [];
        foreach (array_unique($donnees['elements']) as $cle) {
            $erreur = $this->appliquer($request, $cle, $donnees['decision'], $donnees['motif'] ?? null);
            $erreur === null ? $reussis++ : $echecs[] = $erreur;
        }

        $retour = redirect()->route('admin.a-valider');
        if ($reussis > 0) {
            $retour->with('succes', trans_choice('admin.a_valider.messages.'.$donnees['decision'], $reussis, ['n' => $reussis]));
        }

        return $echecs ? $retour->with('erreur', implode(' ', $echecs)) : $retour;
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
                'type' => app(TypesBoutiqueController::class)->{$decision}($sousRequete, TypeBoutique::query()->findOrFail($id)),
                'niveaux', 'zones', 'unites' => app(NiveauxZonesController::class)->{$decision}($sousRequete, $type, $id),
                'campagne' => app(CampagnesBoutiquesController::class)->{$decision}($sousRequete, CampagneBoutiques::query()->findOrFail($id)),
                'phase' => app(PhaseController::class)->{$decision}($sousRequete, $id),
                // Demande d'autorisation de modifier un site validé : acceptée (motif de la demande) ou refusée (motif commun).
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
        $session->forget(['succes', 'erreur']);

        return $erreur;
    }

    private function avecMotif(Request $requete, string $motif): Request
    {
        $requete->merge(['motif' => mb_strlen($motif) >= 5 ? $motif : __('admin.a_valider.motif_autorisation')]);

        return $requete;
    }
}
