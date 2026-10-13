<?php

namespace App\Http\Controllers\Admin;

use App\Enums\CanalPaiement;
use App\Enums\Droit;
use App\Enums\StatutCampagneBoutiques;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\CampagneBoutiquesRequest;
use App\Models\CampagneBoutiques;
use App\Models\CandidatureBoutique;
use App\Models\ParametrageDecision;
use App\Models\Site;
use App\Models\TirageBoutiques;
use App\Models\TypeBoutique;
use App\Services\Audit\Journal;
use App\Services\Backoffice\ConfirmationRenforcee;
use App\Services\Backoffice\RegleGestionException;
use App\Services\Boutiques\LotsCampagne;
use App\Services\Boutiques\TirageAuSort;
use App\Services\Parametrage\WorkflowParametrage;
use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

/**
 * Campagnes d'attribution des boutiques : le RÉGULATEUR saisit et soumet (catégories, cautions),
 * le VALIDATEUR confirme ou rejette, puis ouvre et clôture les candidatures.
 */
class CampagnesBoutiquesController extends Controller
{
    public function __construct(
        private readonly WorkflowParametrage $workflow,
        private readonly Journal $journal,
        private readonly ConfirmationRenforcee $confirmation,
        private readonly LotsCampagne $lots,
    ) {}

    public function index(): View
    {
        Gate::authorize(Droit::BoutiquesVoir->value);

        return view('admin.boutiques.index', [
            'campagnes' => CampagneBoutiques::query()->withSum('categories', 'nombre_boutiques')->latest('id')->paginate(20),
        ]);
    }

    public function create(): View
    {
        Gate::authorize(Droit::BoutiquesSaisir->value);

        return view('admin.boutiques.formulaire', [
            'campagne' => new CampagneBoutiques([
                'date_ouverture' => now()->addMonth()->startOfDay()->setTime(8, 0),
                'date_cloture' => now()->addMonths(2)->setTime(23, 59),
                'delai_paiement_heures' => 72,
                'canaux_actifs' => array_column(CanalPaiement::cases(), 'value'),
            ]),
            ...$this->donneesFormulaire(null),
            'mode' => 'creation',
        ]);
    }

    public function store(CampagneBoutiquesRequest $request): RedirectResponse
    {
        Gate::authorize(Droit::BoutiquesSaisir->value);

        try {
            $campagne = DB::transaction(function () use ($request) {
                $campagne = CampagneBoutiques::create([...$request->parametres(), 'statut' => StatutCampagneBoutiques::Brouillon, 'cree_par' => $request->user()->id]);
                $this->lots->definir($campagne, $request->cautions());
                $this->journal->ecrire($request->user(), 'campagne_boutiques.creee', 'CampagneBoutiques', $campagne->id, null, ['nom' => $campagne->nom]);

                return $campagne;
            });
        } catch (RegleGestionException $e) {
            return back()->withInput()->withErrors(['cautions' => $e->getMessage()]);
        }

        return redirect()->route('admin.boutiques.show', $campagne)->with('succes', __('admin.boutiques.messages.enregistree'));
    }

    public function show(Request $request, CampagneBoutiques $campagne): View
    {
        Gate::authorize(Droit::BoutiquesVoir->value);

        $versions = $campagne->versions()->with('auteur')->get();
        $soumise = $versions->last();
        $precedente = $versions->count() > 1 ? $versions->get($versions->count() - 2) : null;

        return view('admin.boutiques.show', [
            'campagne' => $campagne->load('categories.site'),
            'versions' => $versions->reverse()->values(),
            'decisions' => ParametrageDecision::query()->where('objet_type', $campagne->typeParametrage())->where('objet_id', $campagne->id)
                ->with('decideur')->orderByDesc('id')->get(),
            'differentiel' => $campagne->estSoumis() && $soumise
                ? WorkflowParametrage::differentiel($precedente?->parametres ?? [], $soumise->parametres)
                : [],
            'premiereVersion' => $precedente === null,
            'totpExige' => ConfirmationRenforcee::totpExige($request->user()),
            // Candidatures par catégorie et par statut, résultats des tirages.
            'compteurs' => CandidatureBoutique::query()->where('campagne_boutiques_id', $campagne->id)
                ->selectRaw('categorie_boutique_id, statut, count(*) as nombre')->groupBy('categorie_boutique_id', 'statut')->toBase()->get()
                ->groupBy('categorie_boutique_id')->map(fn ($lignes) => $lignes->pluck('nombre', 'statut')),
            'tirages' => TirageBoutiques::query()->where('campagne_boutiques_id', $campagne->id)->with(['categorie', 'tireur'])->get(),
        ]);
    }

    public function edit(CampagneBoutiques $campagne): View|RedirectResponse
    {
        Gate::authorize(Droit::BoutiquesSaisir->value);

        if (! $campagne->estModifiable()) {
            return redirect()->route('admin.boutiques.show', $campagne)->with('erreur', __('admin.parametrage.regles.non_modifiable'));
        }

        return view('admin.boutiques.formulaire', [
            'campagne' => $campagne,
            ...$this->donneesFormulaire($campagne),
            'mode' => 'modification',
        ]);
    }

    public function update(CampagneBoutiquesRequest $request, CampagneBoutiques $campagne): RedirectResponse
    {
        Gate::authorize(Droit::BoutiquesSaisir->value);

        if (! $campagne->estModifiable()) {
            return redirect()->route('admin.boutiques.show', $campagne)->with('erreur', __('admin.parametrage.regles.non_modifiable'));
        }

        try {
            DB::transaction(function () use ($request, $campagne) {
                $campagne->fill($request->parametres())->save();
                $this->lots->definir($campagne, $request->cautions());
                $this->journal->ecrire($request->user(), 'campagne_boutiques.modifiee', 'CampagneBoutiques', $campagne->id);
            });
        } catch (RegleGestionException $e) {
            return back()->withInput()->withErrors(['cautions' => $e->getMessage()]);
        }

        return redirect()->route('admin.boutiques.show', $campagne)->with('succes', __('admin.boutiques.messages.enregistree'));
    }

    public function soumettre(Request $request, CampagneBoutiques $campagne): RedirectResponse
    {
        Gate::authorize(Droit::BoutiquesSaisir->value);

        return $this->executer($campagne, function () use ($request, $campagne) {
            // Nombres de boutiques recomptés dans le référentiel au moment de la soumission.
            DB::transaction(function () use ($request, $campagne) {
                $this->lots->definir($campagne, $this->cautionsActuelles($campagne));
                $this->workflow->soumettre($campagne, $request->user());
            });
        }, 'admin.parametrage.messages.soumis');
    }

    public function confirmer(Request $request, CampagneBoutiques $campagne): RedirectResponse
    {
        Gate::authorize(Droit::BoutiquesConfirmer->value);

        return $this->executer($campagne, fn () => $this->workflow->confirmer($campagne, $request->user()), 'admin.parametrage.messages.confirme');
    }

    public function rejeter(Request $request, CampagneBoutiques $campagne): RedirectResponse
    {
        Gate::authorize(Droit::BoutiquesConfirmer->value);
        $motif = $request->validate(['motif' => ['required', 'string', 'min:5', 'max:1000']], ['motif.*' => __('admin.utilisateurs.erreurs.motif')])['motif'];

        return $this->executer($campagne, fn () => $this->workflow->rejeter($campagne, $request->user(), $motif), 'admin.parametrage.messages.rejete');
    }

    public function ouvrir(Request $request, CampagneBoutiques $campagne): RedirectResponse
    {
        Gate::authorize(Droit::BoutiquesPiloter->value);

        return $this->executer($campagne, function () use ($request, $campagne) {
            $this->confirmation->verifier($request, $request->user());

            if ($campagne->statut !== StatutCampagneBoutiques::Confirmee) {
                throw new RegleGestionException(__('admin.boutiques.regles.ouverture_confirmee'));
            }
            if (CampagneBoutiques::query()->where('statut', StatutCampagneBoutiques::Ouverte)->exists()) {
                throw new RegleGestionException(__('admin.boutiques.regles.une_seule_ouverte'));
            }

            DB::transaction(function () use ($campagne) {
                $this->lots->engager($campagne);
                $campagne->forceFill(['statut' => StatutCampagneBoutiques::Ouverte, 'ouverte_le' => now()])->save();
            });
            $this->journal->ecrire($request->user(), 'campagne_boutiques.ouverte', 'CampagneBoutiques', $campagne->id, ['statut' => 'CONFIRMEE'], ['statut' => 'OUVERTE']);
        }, 'admin.boutiques.messages.ouverte');
    }

    public function cloturer(Request $request, CampagneBoutiques $campagne): RedirectResponse
    {
        Gate::authorize(Droit::BoutiquesPiloter->value);

        return $this->executer($campagne, function () use ($request, $campagne) {
            $this->confirmation->verifier($request, $request->user());

            if ($campagne->statut !== StatutCampagneBoutiques::Ouverte) {
                throw new RegleGestionException(__('admin.boutiques.regles.cloture_ouverte'));
            }

            $campagne->forceFill(['statut' => StatutCampagneBoutiques::Cloturee, 'cloturee_le' => now()])->save();
            $this->journal->ecrire($request->user(), 'campagne_boutiques.cloturee', 'CampagneBoutiques', $campagne->id, ['statut' => 'OUVERTE'], ['statut' => 'CLOTUREE']);
        }, 'admin.boutiques.messages.cloturee');
    }

    /** Tirage au sort, catégorie par catégorie (VALIDATEUR, confirmation renforcée). */
    public function tirer(Request $request, CampagneBoutiques $campagne, TirageAuSort $tirage): RedirectResponse
    {
        Gate::authorize(Droit::BoutiquesPiloter->value);

        return $this->executer($campagne, function () use ($request, $campagne, $tirage) {
            $this->confirmation->verifier($request, $request->user());
            $tirage->tirer($campagne, $request->user());
        }, 'admin.boutiques.messages.tiree');
    }

    /**
     * Sites confirmés, types disponibles par site et cautions déjà saisies (formulaire).
     *
     * @return array<string, mixed>
     */
    private function donneesFormulaire(?CampagneBoutiques $campagne): array
    {
        $disponibilites = LotsCampagne::disponibilites();

        return [
            'sites' => Site::query()->confirmes()->whereIn('id', $disponibilites->keys())->orderBy('nom')->get(),
            'types' => TypeBoutique::query()->orderBy('ordre')->orderBy('libelle')->get()->keyBy('id'),
            'disponibilites' => $disponibilites,
            'cautions' => $campagne ? $this->cautionsActuelles($campagne) : [],
        ];
    }

    /** @return array<int, array<int, int>> */
    private function cautionsActuelles(CampagneBoutiques $campagne): array
    {
        $cautions = [];
        foreach ($campagne->categories()->whereNotNull('site_id')->get() as $lot) {
            $cautions[$lot->site_id][$lot->type_boutique_id] = $lot->caution;
        }

        return $cautions;
    }

    private function executer(CampagneBoutiques $campagne, Closure $action, string $message): RedirectResponse
    {
        try {
            $action();
        } catch (RegleGestionException $e) {
            return redirect()->route('admin.boutiques.show', $campagne)->with('erreur', $e->getMessage());
        }

        return redirect()->route('admin.boutiques.show', $campagne)->with('succes', __($message));
    }
}
