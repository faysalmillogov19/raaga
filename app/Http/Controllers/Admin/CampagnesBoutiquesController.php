<?php

namespace App\Http\Controllers\Admin;

use App\Enums\CanalPaiement;
use App\Enums\Droit;
use App\Enums\StatutCampagneBoutiques;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\CampagneBoutiquesRequest;
use App\Models\CampagneBoutiques;
use App\Models\ParametrageDecision;
use App\Services\Audit\Journal;
use App\Services\Backoffice\ConfirmationRenforcee;
use App\Services\Backoffice\RegleGestionException;
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
            'categories' => [],
            'mode' => 'creation',
        ]);
    }

    public function store(CampagneBoutiquesRequest $request): RedirectResponse
    {
        Gate::authorize(Droit::BoutiquesSaisir->value);

        $campagne = DB::transaction(function () use ($request) {
            $campagne = CampagneBoutiques::create([...$request->parametres(), 'statut' => StatutCampagneBoutiques::Brouillon, 'cree_par' => $request->user()->id]);
            $this->remplacerCategories($campagne, $request->categories());
            $this->journal->ecrire($request->user(), 'campagne_boutiques.creee', 'CampagneBoutiques', $campagne->id, null, ['nom' => $campagne->nom]);

            return $campagne;
        });

        return redirect()->route('admin.boutiques.show', $campagne)->with('succes', __('admin.boutiques.messages.enregistree'));
    }

    public function show(Request $request, CampagneBoutiques $campagne): View
    {
        Gate::authorize(Droit::BoutiquesVoir->value);

        $versions = $campagne->versions()->with('auteur')->get();
        $soumise = $versions->last();
        $precedente = $versions->count() > 1 ? $versions->get($versions->count() - 2) : null;

        return view('admin.boutiques.show', [
            'campagne' => $campagne->load('categories'),
            'versions' => $versions->reverse()->values(),
            'decisions' => ParametrageDecision::query()->where('objet_type', $campagne->typeParametrage())->where('objet_id', $campagne->id)
                ->with('decideur')->orderByDesc('id')->get(),
            'differentiel' => $campagne->estSoumis() && $soumise
                ? WorkflowParametrage::differentiel($precedente?->parametres ?? [], $soumise->parametres)
                : [],
            'premiereVersion' => $precedente === null,
            'totpExige' => ConfirmationRenforcee::totpExige($request->user()),
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
            'categories' => $campagne->categories()->get(['libelle', 'description', 'nombre_boutiques', 'caution'])->toArray(),
            'mode' => 'modification',
        ]);
    }

    public function update(CampagneBoutiquesRequest $request, CampagneBoutiques $campagne): RedirectResponse
    {
        Gate::authorize(Droit::BoutiquesSaisir->value);

        if (! $campagne->estModifiable()) {
            return redirect()->route('admin.boutiques.show', $campagne)->with('erreur', __('admin.parametrage.regles.non_modifiable'));
        }

        DB::transaction(function () use ($request, $campagne) {
            $campagne->fill($request->parametres())->save();
            $this->remplacerCategories($campagne, $request->categories());
            $this->journal->ecrire($request->user(), 'campagne_boutiques.modifiee', 'CampagneBoutiques', $campagne->id);
        });

        return redirect()->route('admin.boutiques.show', $campagne)->with('succes', __('admin.boutiques.messages.enregistree'));
    }

    public function soumettre(Request $request, CampagneBoutiques $campagne): RedirectResponse
    {
        Gate::authorize(Droit::BoutiquesSaisir->value);

        return $this->executer($campagne, fn () => $this->workflow->soumettre($campagne, $request->user()), 'admin.parametrage.messages.soumis');
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

            $campagne->forceFill(['statut' => StatutCampagneBoutiques::Ouverte, 'ouverte_le' => now()])->save();
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

    /** @param list<array<string, mixed>> $categories */
    private function remplacerCategories(CampagneBoutiques $campagne, array $categories): void
    {
        $campagne->categories()->delete();
        foreach ($categories as $i => $categorie) {
            $campagne->categories()->create([...$categorie, 'ordre' => $i + 1]);
        }
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
