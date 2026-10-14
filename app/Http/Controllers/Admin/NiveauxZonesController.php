<?php

namespace App\Http\Controllers\Admin;

use App\Enums\Droit;
use App\Enums\StatutTypeBoutique;
use App\Http\Controllers\Controller;
use App\Models\Boutique;
use App\Models\Niveau;
use App\Models\ZoneSite;
use App\Services\Audit\Journal;
use App\Services\Backoffice\RegleGestionException;
use App\Support\Tableau\Colonne;
use App\Support\Tableau\Filtre;
use App\Support\Tableau\ResultatTableau;
use App\Support\Tableau\Tableau;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\HtmlString;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Niveaux et zones : deux onglets (tableau filtrable, une fiche par élément), gérés comme les types de boutiques.
 * GESTIONNAIRE DES SITES ET BOUTIQUES : ajoute (soumis au VALIDATEUR), modifie le libellé, la description, le rang ou
 * l'ordre à tout moment, désactive ou réactive, supprime un élément jamais utilisé et non « par défaut ». Code jamais
 * modifié. VALIDATEUR : valide ou rejette. Autres profils : lecture seule. « {liste} » vaut « niveaux » ou « zones ».
 */
class NiveauxZonesController extends Controller
{
    /** Liste => modèle. */
    private const LISTES = ['niveaux' => Niveau::class, 'zones' => ZoneSite::class];

    public function __construct(private readonly Journal $journal) {}

    public function index(Request $request, string $liste): Response|StreamedResponse|View
    {
        Gate::authorize(Droit::SitesVoir->value);
        $classe = $this->classe($liste);
        $niveaux = $liste === 'niveaux';
        $z = __('admin.zones');
        $table = (new $classe)->getTable();
        // Boutiques qui portent ce code (niveau, ou bloc pour une zone) : sous-requête, triable.
        $compte = Boutique::query()->selectRaw('count(*)')->whereColumn($niveaux ? 'niveau' : 'bloc', $table.'.code');
        $statut = fn (Model $e) => new HtmlString('<span class="bo-etiquette '.match ($e->statut) {
            StatutTypeBoutique::Valide => 'bo-etiquette-succes', StatutTypeBoutique::AValider => 'bo-etiquette-attention', default => 'bo-etiquette-echec',
        }.'">'.e($e->statut->label()).'</span>'.($e->actif ? '' : ' <span class="bo-etiquette bo-etiquette-neutre">'.e($z['desactive']).'</span>'));

        return Tableau::pour($classe::query()->select($table.'.*'), $liste)
            ->colonnes([
                Colonne::make('code', $z['colonnes']['code'])->triable()->recherchable()->classe('font-mono font-semibold'),
                Colonne::make('libelle', $z['colonnes']['libelle'])->triable()->recherchable(),
                $niveaux ? null : Colonne::make('description', $z['colonnes']['description'])->recherchable(),
                Colonne::make($niveaux ? 'rang' : 'ordre', $z['colonnes'][$niveaux ? 'rang' : 'ordre'])->numerique()->triable(),
                Colonne::make('statut', $z['colonnes']['statut'])->triable()->valeur($statut)
                    ->texte(fn (Model $e) => $e->statut->label().($e->actif ? '' : ' · '.$z['desactive'])),
                Colonne::make('par_defaut', $z['colonnes']['par_defaut'])->triable()
                    ->valeur(fn (Model $e) => $e->par_defaut ? new HtmlString('<span class="bo-etiquette bo-etiquette-neutre">'.e($z['par_defaut']).'</span>') : $z['non'])
                    ->texte(fn (Model $e) => $e->par_defaut ? $z['oui'] : $z['non']),
                Colonne::make('utilise', $z['colonnes']['utilisation'])->numerique()->triable('boutiques_count')->avec(fn ($q) => $q->addSelect(['boutiques_count' => $compte]))
                    ->valeur(fn (Model $e) => trans_choice('admin.zones.boutiques', (int) $e->boutiques_count, ['n' => format_nombre((int) $e->boutiques_count)]))
                    ->texte(fn (Model $e) => (int) $e->boutiques_count),
                Colonne::make('actions', $z['colonnes']['actions'])->vue('admin.zones._actions', 'el')->exportable(false),
            ])
            ->filtres([
                Filtre::make('statut', $z['colonnes']['statut'], collect(StatutTypeBoutique::cases())->mapWithKeys(fn ($s) => [$s->value => $s->label()])->all(), 'statut'),
                Filtre::make('actif', __('admin.types.filtre_actif'), [1 => __('admin.types.actif'), 0 => $z['desactive']], 'actif'),
                Filtre::make('par_defaut', $z['colonnes']['par_defaut'], [1 => $z['oui'], 0 => $z['non']], 'par_defaut'),
            ])
            ->triParDefaut($niveaux ? 'rang' : 'ordre')
            ->export($niveaux ? 'Raaga_niveaux' : 'Raaga_zones')
            ->repondre($request, fn (ResultatTableau $t) => view('admin.zones.liste', ['liste' => $liste, 'tableau' => $t]));
    }

    /** Fiche : détail, modification (GESTIONNAIRE), validation (VALIDATEUR), historique des versions. */
    public function show(string $liste, int $id): View
    {
        Gate::authorize(Droit::SitesVoir->value);
        $element = $this->element($liste, $id);

        return view('admin.zones.fiche', [
            'liste' => $liste,
            'element' => $element,
            'utilisations' => $element->nombreUtilisations(),
            'versions' => $element->versions(),
        ]);
    }

    /** Nouvel élément : utilisable seulement après validation. */
    public function store(Request $request, string $liste): RedirectResponse
    {
        Gate::authorize(Droit::SitesGerer->value);
        $classe = $this->classe($liste);
        if (is_string($request->input('code'))) {
            $request->merge(['code' => mb_strtoupper(trim($request->input('code')))]);
        }
        $donnees = $request->validate([
            'code' => ['required', 'string', 'regex:'.$classe::FORMAT_CODE, Rule::unique((new $classe)->getTable(), 'code')],
            ...$this->reglesLibres($liste),
        ], [], __('admin.zones.attributs'));

        $element = DB::transaction(function () use ($request, $classe, $donnees) {
            $element = $classe::create([...$donnees, 'actif' => true, 'par_defaut' => false, 'statut' => StatutTypeBoutique::AValider]);
            $element->forceFill(['demande_par' => $request->user()->id])->save();
            $element->creerVersion($request->user(), __('admin.types.motifs.creation'));

            return $element;
        });
        $this->journal->ecrire($request->user(), $classe::typeReferentiel().'.cree', class_basename($classe), $element->id, null, $donnees);

        return redirect()->route('admin.referencement.'.$liste)->with('succes', __('admin.zones.messages.creee'));
    }

    /** Libellé, description, rang ou ordre : à tout moment, sans validation. */
    public function update(Request $request, string $liste, int $id): RedirectResponse
    {
        Gate::authorize(Droit::SitesGerer->value);
        $element = $this->element($liste, $id);
        $donnees = $request->validate($this->reglesLibres($liste), [], __('admin.zones.attributs'));

        return $this->executer($liste, $element, function () use ($request, $element, $donnees) {
            $avant = $element->only(array_keys($donnees));
            $element->fill($donnees);
            if (! $element->isDirty()) {
                return;
            }
            $element->save();
            $element->creerVersion($request->user(), __('admin.types.motifs.modification'));
            $this->journal->ecrire($request->user(), $element::typeReferentiel().'.modifie', class_basename($element), $element->id, $avant, $element->only(array_keys($donnees)));
        }, 'admin.zones.messages.renommee');
    }

    public function activer(Request $request, string $liste, int $id): RedirectResponse
    {
        return $this->activation($request, $liste, $this->element($liste, $id), true);
    }

    public function desactiver(Request $request, string $liste, int $id): RedirectResponse
    {
        return $this->activation($request, $liste, $this->element($liste, $id), false);
    }

    /** Suppression : jamais pour un élément « par défaut » ni pour un élément utilisé. Retour à la liste. */
    public function destroy(Request $request, string $liste, int $id): RedirectResponse
    {
        Gate::authorize(Droit::SitesGerer->value);
        $element = $this->element($liste, $id);

        try {
            DB::transaction(function () use ($request, $element) {
                if ($element->par_defaut || $element->nombreUtilisations() > 0) {
                    throw new RegleGestionException(__($element->par_defaut ? 'admin.zones.regles.defaut' : 'admin.zones.regles.utilisee'));
                }
                $this->journal->ecrire($request->user(), $element::typeReferentiel().'.supprime', class_basename($element), $element->id, ['code' => $element->code, 'libelle' => $element->libelle]);
                $element->delete();
            });
        } catch (RegleGestionException $e) {
            return redirect()->route('admin.referencement.'.$liste)->with('erreur', $e->getMessage());
        }

        return redirect()->route('admin.referencement.'.$liste)->with('succes', __('admin.zones.messages.supprimee'));
    }

    public function confirmer(Request $request, string $liste, int $id): RedirectResponse
    {
        Gate::authorize(Droit::SitesConfirmer->value);
        $element = $this->element($liste, $id);

        return $this->executer($liste, $element, function () use ($request, $element) {
            $this->exigerEnAttente($request, $element);
            $element->forceFill(['statut' => StatutTypeBoutique::Valide, 'valide_par' => $request->user()->id, 'valide_le' => now(), 'motif_rejet' => null])->save();
            $element->creerVersion($request->user(), __('admin.types.motifs.confirmation'));
            $this->journal->ecrire($request->user(), $element::typeReferentiel().'.confirme', class_basename($element), $element->id);
        }, 'admin.zones.messages.confirmee');
    }

    public function rejeter(Request $request, string $liste, int $id): RedirectResponse
    {
        Gate::authorize(Droit::SitesConfirmer->value);
        $element = $this->element($liste, $id);
        $motif = $request->validate(['motif' => ['required', 'string', 'min:5', 'max:1000']], ['motif.*' => __('admin.utilisateurs.erreurs.motif')])['motif'];

        return $this->executer($liste, $element, function () use ($request, $element, $motif) {
            $this->exigerEnAttente($request, $element);
            $element->forceFill(['statut' => StatutTypeBoutique::Rejete, 'motif_rejet' => $motif])->save();
            $element->creerVersion($request->user(), __('admin.types.motifs.rejet', ['motif' => $motif]));
            $this->journal->ecrire($request->user(), $element::typeReferentiel().'.rejete', class_basename($element), $element->id, null, ['motif' => $motif]);
        }, 'admin.zones.messages.rejetee');
    }

    private function activation(Request $request, string $liste, Niveau|ZoneSite $element, bool $actif): RedirectResponse
    {
        Gate::authorize(Droit::SitesGerer->value);

        return $this->executer($liste, $element, function () use ($request, $element, $actif) {
            $element->forceFill(['actif' => $actif])->save();
            $element->creerVersion($request->user(), __($actif ? 'admin.types.motifs.reactivation' : 'admin.types.motifs.desactivation'));
            $this->journal->ecrire($request->user(), $element::typeReferentiel().($actif ? '.reactive' : '.desactive'), class_basename($element), $element->id);
        }, $actif ? 'admin.zones.messages.reactivee' : 'admin.zones.messages.desactivee');
    }

    /** @return array<string, list<mixed>> */
    private function reglesLibres(string $liste): array
    {
        return $liste === 'niveaux'
            ? ['libelle' => ['required', 'string', 'max:80'], 'rang' => ['required', 'integer', 'min:-9', 'max:99']]
            : ['libelle' => ['required', 'string', 'max:100'], 'description' => ['nullable', 'string', 'max:300'], 'ordre' => ['nullable', 'integer', 'min:0', 'max:9999']];
    }

    /** @return class-string<Niveau|ZoneSite> */
    private function classe(string $liste): string
    {
        return self::LISTES[$liste] ?? abort(404);
    }

    private function element(string $liste, int $id): Niveau|ZoneSite
    {
        return $this->classe($liste)::query()->findOrFail($id);
    }

    /** @throws RegleGestionException */
    private function exigerEnAttente(Request $request, Niveau|ZoneSite $element): void
    {
        if ($element->statut !== StatutTypeBoutique::AValider) {
            throw new RegleGestionException(__('admin.zones.regles.rien_a_valider'));
        }
        if ($element->demande_par === $request->user()->id) {
            throw new RegleGestionException(__('admin.parametrage.regles.quatre_yeux'));
        }
    }

    /** Action sur un élément ; retour à sa fiche, avec le message ou l'erreur. */
    private function executer(string $liste, Niveau|ZoneSite $element, Closure $action, string $message): RedirectResponse
    {
        $fiche = redirect()->route('admin.referencement.zones.show', [$liste, $element->id]);
        try {
            DB::transaction($action);
        } catch (RegleGestionException $e) {
            return $fiche->with('erreur', $e->getMessage());
        }

        return $fiche->with('succes', __($message));
    }
}
