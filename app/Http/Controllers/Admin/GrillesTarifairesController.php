<?php

namespace App\Http\Controllers\Admin;

use App\Enums\Droit;
use App\Enums\StatutBoutique;
use App\Enums\StatutGrilleTarifaire;
use App\Http\Controllers\Admin\Concerns\EnregistreEtSoumet;
use App\Http\Controllers\Controller;
use App\Models\Boutique;
use App\Models\GrilleTarifaire;
use App\Models\Niveau;
use App\Models\ParametrageDecision;
use App\Models\Site;
use App\Models\TypeBoutique;
use App\Models\Unite;
use App\Models\User;
use App\Services\Audit\Journal;
use App\Services\Backoffice\RegleGestionException;
use App\Services\Boutiques\GrilleTarifaireExcel;
use App\Services\Parametrage\WorkflowParametrage;
use App\Support\BadgeStatut;
use App\Support\Tableau\Colonne;
use App\Support\Tableau\ResultatTableau;
use App\Support\Tableau\Tableau;
use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\HtmlString;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Grilles tarifaires des boutiques, une par site : le RÉGULATEUR saisit (loyer au m², mois de caution, mois d'avance
 * par niveau et type) et soumet ; le VALIDATEUR confirme ou rejette (quatre yeux, versions figées, même circuit que
 * les tranches). Les montants des boutiques sont calculés, jamais saisis.
 */
class GrillesTarifairesController extends Controller
{
    use EnregistreEtSoumet;

    public function __construct(private readonly WorkflowParametrage $workflow, private readonly Journal $journal, private readonly GrilleTarifaireExcel $excel) {}

    /** Grilles des sites confirmés ; le RÉGULATEUR coche les brouillons et rejetées pour les soumettre ensemble. */
    public function index(Request $request): Response|StreamedResponse|View
    {
        Gate::authorize(Droit::GrilleVoir->value);
        $g = __('admin.grilles');

        return Tableau::pour(Site::query()->confirmes()->with('grilleTarifaire'), 'grilles')
            ->colonnes([
                Colonne::make('nom', $g['colonnes']['site'])->triable()->recherchable(['nom', 'code'])
                    ->valeur(fn (Site $s) => new HtmlString('<a href="'.e(route('admin.boutiques.grilles.show', $s)).'" class="font-bold text-vert-fonce underline underline-offset-2">'
                        .e(($s->code ? $s->code.' · ' : '').$s->nom).'</a>'))
                    ->texte(fn (Site $s) => $s->nom),
                Colonne::make('statut', $g['colonnes']['statut'])
                    ->valeur(fn (Site $s) => $s->grilleTarifaire ? BadgeStatut::html($s->grilleTarifaire->statut) : $g['aucune_grille'])
                    ->texte(fn (Site $s) => $s->grilleTarifaire?->statut->label() ?? $g['aucune_grille']),
                Colonne::make('version', $g['colonnes']['version'])->numerique()->valeur(fn (Site $s) => $s->grilleTarifaire?->confirmee()['version'] ?? ''),
            ])
            ->selection('grille', $request->user())
            ->triParDefaut('nom')
            ->unite($g['unite_liste'])
            ->repondre($request, fn (ResultatTableau $t) => view('admin.grilles.index', ['tableau' => $t]));
    }

    public function show(Site $site): View
    {
        Gate::authorize(Droit::GrilleVoir->value);
        $grille = $site->grilleTarifaire;
        $versions = $grille?->versions()->with('auteur')->get() ?? collect();
        $soumise = $versions->last();
        $precedente = $versions->count() > 1 ? $versions->get($versions->count() - 2) : null;
        $differentiel = $grille?->estSoumis() && $soumise ? WorkflowParametrage::differentiel($precedente?->parametres ?? [], $soumise->parametres) : [];
        unset($differentiel['lignes']); // Valeurs exactes : le résumé « grille » suffit au différentiel.

        return view('admin.grilles.show', [
            'site' => $site,
            'grille' => $grille,
            'combinaisons' => $this->combinaisons($site),
            'lignes' => $grille?->lignes()->with('unite')->get()->keyBy(fn ($l) => GrilleTarifaire::cle($l->niveau, $l->type_boutique_id)) ?? collect(),
            'confirmee' => $grille?->confirmee(),
            'versions' => $versions->reverse()->values(),
            'decisions' => $grille ? ParametrageDecision::query()->where('objet_type', $grille->typeParametrage())->where('objet_id', $grille->id)
                ->with('decideur')->orderByDesc('id')->get() : collect(),
            'differentiel' => $differentiel,
            'premiereVersion' => $precedente === null,
        ]);
    }

    public function edit(Site $site): View|RedirectResponse
    {
        Gate::authorize(Droit::BoutiquesSaisir->value);
        $grille = $site->grilleTarifaire;
        if ($grille && ! $grille->estModifiable()) {
            return redirect()->route('admin.boutiques.grilles.show', $site)->with('erreur', __('admin.parametrage.regles.non_modifiable'));
        }

        return view('admin.grilles.formulaire', [
            'site' => $site,
            'combinaisons' => $this->combinaisons($site),
            'unitesGrille' => Unite::pourGrille(),
            'lignes' => $grille?->lignes()->with('unite')->get()->keyBy(fn ($l) => GrilleTarifaire::cle($l->niveau, $l->type_boutique_id)) ?? collect(),
        ]);
    }

    public function update(Request $request, Site $site): RedirectResponse
    {
        Gate::authorize(Droit::BoutiquesSaisir->value);
        $combinaisons = $this->combinaisons($site);
        $unites = [...Unite::pourGrille()->keys(), ...($site->grilleTarifaire?->lignes()->pluck('unite_id') ?? [])];
        $regles = [];
        foreach ($combinaisons as $c) {
            $p = 'lignes.'.$c['niveau']->code.'.'.$c['type']->id;
            $regles[$p.'.loyer_m2'] = ['nullable', 'integer', 'min:1', 'max:100000000'];
            // Unités du référentiel « Unités » (validées, actives, avec un mode de calcul) ; l'unité déjà enregistrée reste admise.
            $regles[$p.'.unite_id'] = ['required_with:'.$p.'.loyer_m2', 'nullable', 'integer', Rule::in($unites)];
            $regles[$p.'.mois_caution'] = ['required_with:'.$p.'.loyer_m2', 'nullable', 'integer', 'min:0', 'max:240'];
            $regles[$p.'.mois_avance'] = ['required_with:'.$p.'.loyer_m2', 'nullable', 'integer', 'min:0', 'max:240'];
        }
        $donnees = $request->validate($regles, [], __('admin.grilles.attributs'));

        try {
            $this->enregistrer($site, $combinaisons, $donnees['lignes'] ?? [], $request->user());
        } catch (RegleGestionException $e) {
            return redirect()->route('admin.boutiques.grilles.show', $site)->with('erreur', $e->getMessage());
        }

        return $this->puisSoumettre($request, redirect()->route('admin.boutiques.grilles.show', $site)->avecSucces('admin.grilles.messages.enregistree'),
            'grille', $site, route('admin.boutiques.grilles.edit', $site));
    }

    /**
     * Fichier Excel de la grille du site, à compléter hors ligne : une ligne par niveau et type présents, valeurs en
     * vigueur ou proposées, onglet de contrôle des montants de chaque boutique.
     */
    public function exporter(Site $site): StreamedResponse
    {
        Gate::authorize(Droit::GrilleVoir->value);

        return $this->excel->telecharger($site, $this->combinaisons($site));
    }

    /**
     * Import de la grille remplie : contrôle complet du fichier (aucune écriture si une seule erreur), puis
     * remplacement du brouillon, à relire et soumettre au VALIDATEUR comme une saisie à l'écran.
     */
    public function importer(Request $request, Site $site): RedirectResponse
    {
        Gate::authorize(Droit::BoutiquesSaisir->value);
        $fichier = $request->validate(['fichier' => ['required', 'file', 'mimes:xlsx', 'max:5120']],
            ['fichier.*' => __('admin.grilles.excel.erreurs.fichier')])['fichier'];
        if ($site->grilleTarifaire && ! $site->grilleTarifaire->estModifiable()) {
            return redirect()->route('admin.boutiques.grilles.show', $site)->with('erreur', __('admin.grilles.excel.erreurs.non_modifiable'));
        }
        $combinaisons = $this->combinaisons($site);
        $analyse = $this->excel->analyser($fichier->getRealPath(), $combinaisons);
        if ($analyse['erreurs'] !== []) {
            return redirect()->route('admin.boutiques.grilles.edit', $site)->with('erreurs_import', $analyse['erreurs'])
                ->with('erreur', trans_choice('admin.grilles.excel.messages.refuse', count($analyse['erreurs']), ['n' => count($analyse['erreurs'])]));
        }

        try {
            $this->enregistrer($site, $combinaisons, $analyse['saisies'], $request->user());
        } catch (RegleGestionException $e) {
            return redirect()->route('admin.boutiques.grilles.show', $site)->with('erreur', $e->getMessage());
        }
        $this->journal->ecrire($request->user(), 'grille_tarifaire.importee', 'GrilleTarifaire', $site->grilleTarifaire()->value('id'), null,
            ['fichier' => mb_substr($fichier->getClientOriginalName(), 0, 150), 'lignes' => $analyse['lignes']]);

        return redirect()->route('admin.boutiques.grilles.edit', $site)
            ->avecSucces('admin.grilles.excel.messages.importee', ['n' => $analyse['lignes']]);
    }

    /**
     * Remplace les lignes du brouillon de la grille (saisie à l'écran ou import Excel) : une ligne par combinaison
     * qui a un loyer ; sans loyer, pas de ligne (ces boutiques ne pourront pas entrer en campagne).
     *
     * @param  Collection<int, array>  $combinaisons
     * @param  array<string, array<int, array<string, mixed>>>  $saisies
     *
     * @throws RegleGestionException grille soumise ou confirmée
     */
    private function enregistrer(Site $site, Collection $combinaisons, array $saisies, User $auteur): void
    {
        DB::transaction(function () use ($site, $combinaisons, $saisies, $auteur) {
            $grille = $site->grilleTarifaire ?? GrilleTarifaire::create([
                'site_id' => $site->id, 'statut' => StatutGrilleTarifaire::Brouillon, 'cree_par' => $auteur->id,
            ]);
            if (! $grille->estModifiable()) {
                throw new RegleGestionException(__('admin.parametrage.regles.non_modifiable'));
            }
            $grille->lignes()->delete();
            foreach ($combinaisons as $c) {
                $saisie = $saisies[$c['niveau']->code][$c['type']->id] ?? [];
                if (($saisie['loyer_m2'] ?? null) === null) {
                    continue; // Pas de loyer : pas de ligne (les boutiques concernées ne pourront pas entrer en campagne).
                }
                $grille->lignes()->create([
                    'niveau' => $c['niveau']->code, 'type_boutique_id' => $c['type']->id, 'unite_id' => (int) $saisie['unite_id'], 'loyer_m2' => (int) $saisie['loyer_m2'],
                    'mois_caution' => (int) $saisie['mois_caution'], 'mois_avance' => (int) $saisie['mois_avance'],
                ]);
            }
            $this->journal->ecrire($auteur, 'grille_tarifaire.modifiee', 'GrilleTarifaire', $grille->id, null, $grille->snapshotParametres()['lignes']);
        });
        $site->unsetRelation('grilleTarifaire');
    }

    public function soumettre(Request $request, Site $site): RedirectResponse
    {
        Gate::authorize(Droit::BoutiquesSaisir->value);

        return $this->executer($site, function (GrilleTarifaire $grille) use ($request) {
            if (! $grille->lignes()->exists()) {
                throw new RegleGestionException(__('admin.grilles.regles.vide'));
            }
            $this->workflow->soumettre($grille, $request->user());
        }, 'admin.parametrage.messages.soumis');
    }

    public function confirmer(Request $request, Site $site): RedirectResponse
    {
        Gate::authorize(Droit::BoutiquesConfirmer->value);

        return $this->executer($site, fn (GrilleTarifaire $grille) => $this->workflow->confirmer($grille, $request->user()), 'admin.parametrage.messages.confirme');
    }

    public function rejeter(Request $request, Site $site): RedirectResponse
    {
        Gate::authorize(Droit::BoutiquesConfirmer->value);
        $motif = $request->validate(['motif' => ['required', 'string', 'min:5', 'max:1000']], ['motif.*' => __('admin.utilisateurs.erreurs.motif')])['motif'];

        return $this->executer($site, fn (GrilleTarifaire $grille) => $this->workflow->rejeter($grille, $request->user(), $motif), 'admin.parametrage.messages.rejete');
    }

    /** Remise en révision d'une grille confirmée : la version confirmée reste en vigueur jusqu'à la suivante. */
    public function reviser(Request $request, Site $site): RedirectResponse
    {
        Gate::authorize(Droit::BoutiquesSaisir->value);

        return $this->executer($site, function (GrilleTarifaire $grille) use ($request) {
            if ($grille->statut !== StatutGrilleTarifaire::Confirme) {
                throw new RegleGestionException(__('admin.grilles.regles.revision_confirmee'));
            }
            $grille->forceFill(['statut' => StatutGrilleTarifaire::Brouillon])->save();
            $this->journal->ecrire($request->user(), 'grille_tarifaire.revision', 'GrilleTarifaire', $grille->id, ['statut' => 'CONFIRME'], ['statut' => 'BROUILLON']);
        }, 'admin.grilles.messages.revision');
    }

    /**
     * Niveaux × types présents dans le référentiel du site, avec les surfaces (lots possibles) de chacun.
     *
     * @return Collection<int, array{niveau: Niveau, type: TypeBoutique, surfaces: Collection<string, int>, nombre: int}>
     */
    private function combinaisons(Site $site): Collection
    {
        $types = TypeBoutique::query()->get()->keyBy('id');
        $niveaux = Niveau::query()->get()->keyBy('code');

        return Boutique::query()->where('site_id', $site->id)->whereNotNull('niveau')->where('statut', '!=', StatutBoutique::Retiree)->get()
            ->groupBy(fn (Boutique $b) => GrilleTarifaire::cle($b->niveau, $b->type_boutique_id))
            ->map(fn ($groupe) => [
                'niveau' => $niveaux[$groupe->first()->niveau], 'type' => $types[$groupe->first()->type_boutique_id], 'nombre' => $groupe->count(),
                'surfaces' => $groupe->groupBy(fn (Boutique $b) => $b->surface === null ? '' : (string) $b->surface)->map->count()->sortKeys(SORT_NATURAL),
            ])
            ->sortBy(fn ($c) => [$c['niveau']->rang, $c['type']->ordre, $c['type']->libelle])->values();
    }

    private function executer(Site $site, Closure $action, string $message): RedirectResponse
    {
        try {
            $grille = $site->grilleTarifaire ?? throw new RegleGestionException(__('admin.grilles.regles.vide'));
            $action($grille);
        } catch (RegleGestionException $e) {
            return redirect($this->retour($site))->with('erreur', $e->getMessage());
        }

        return redirect($this->retour($site))->avecSucces($message);
    }

    /** Après une action : la grille, ou « À valider › Grilles » pour le VALIDATEUR (sans l'écran des grilles). */
    private function retour(Site $site): string
    {
        return Gate::allows(Droit::GrilleVoir->value) ? route('admin.boutiques.grilles.show', $site) : route('admin.a-valider.rubrique', 'grilles');
    }
}
