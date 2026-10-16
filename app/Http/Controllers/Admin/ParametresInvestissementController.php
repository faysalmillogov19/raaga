<?php

namespace App\Http\Controllers\Admin;

use App\Enums\Droit;
use App\Enums\StatutGrilleTarifaire;
use App\Http\Controllers\Admin\Concerns\EnregistreEtSoumet;
use App\Http\Controllers\Controller;
use App\Models\ParametrageDecision;
use App\Models\ParametresInvestissement;
use App\Services\Audit\Journal;
use App\Services\Backoffice\RegleGestionException;
use App\Services\Parametrage\WorkflowParametrage;
use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

/**
 * Paramètres d'investissement RAAGA Invest (objectif de collecte, valeur indicative par action, plafond global
 * facultatif) : RÉGULATEUR saisit et soumet, VALIDATEUR confirme ou rejette (« À valider › Données financières »).
 * Le GESTIONNAIRE n'y a pas accès ; aucune valeur de départ.
 */
class ParametresInvestissementController extends Controller
{
    use EnregistreEtSoumet;

    public function __construct(private readonly WorkflowParametrage $workflow, private readonly Journal $journal) {}

    public function show(): View
    {
        Gate::authorize(Droit::TranchesVoir->value);
        $fiche = ParametresInvestissement::fiche();
        $versions = $fiche->exists ? $fiche->versions()->with('auteur')->get() : collect();
        $precedente = $versions->count() > 1 ? $versions->get($versions->count() - 2) : null;

        return view('admin.investissement.show', [
            'fiche' => $fiche,
            'confirmes' => ParametresInvestissement::confirmes(),
            'versions' => $versions->reverse()->values(),
            'decisions' => $fiche->exists ? ParametrageDecision::query()->where('objet_type', $fiche->typeParametrage())->where('objet_id', $fiche->id)
                ->with('decideur')->orderByDesc('id')->get() : collect(),
            'differentiel' => $fiche->estSoumis() && $versions->isNotEmpty()
                ? WorkflowParametrage::differentiel($precedente?->parametres ?? [], $versions->last()->parametres) : [],
            'premiereVersion' => $precedente === null,
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        Gate::authorize(Droit::TranchesSaisir->value);
        $donnees = $request->validate([
            'objectif_collecte' => ['required', 'integer', 'min:1', 'max:999999999999999'],
            'valeur_action' => ['required', 'integer', 'min:1', 'max:999999999999'],
            'minimum_actions' => ['required', 'integer', 'min:1', 'max:10000000'],
            'plafond_global' => ['nullable', 'integer', 'min:1', 'max:999999999999999'],
        ], [], __('admin.investissement.champs'));
        $fiche = ParametresInvestissement::fiche();

        return $this->puisSoumettre($request, $this->executer(function () use ($request, $fiche, $donnees) {
            if ($fiche->exists && ! $fiche->estModifiable()) {
                throw new RegleGestionException(__('admin.parametrage.regles.non_modifiable'));
            }
            $avant = $fiche->only(ParametresInvestissement::PARAMETRES);
            $fiche->fill($donnees)->save();
            $this->journal->ecrire($request->user(), 'investissement.modifie', 'ParametresInvestissement', $fiche->id, $avant, $donnees);
        }, 'admin.investissement.messages.enregistres'), 'investissement', $fiche, route('admin.investissement'), 'modifier-investissement');
    }

    public function soumettre(Request $request): RedirectResponse
    {
        Gate::authorize(Droit::TranchesSaisir->value);

        return $this->executer(fn () => $this->workflow->soumettre($this->ficheExistante(), $request->user()), 'admin.parametrage.messages.soumis');
    }

    public function confirmer(Request $request): RedirectResponse
    {
        Gate::authorize(Droit::TranchesConfirmer->value);

        return $this->executer(fn () => $this->workflow->confirmer($this->ficheExistante(), $request->user()), 'admin.parametrage.messages.confirme');
    }

    public function rejeter(Request $request): RedirectResponse
    {
        Gate::authorize(Droit::TranchesConfirmer->value);
        $motif = $request->validate(['motif' => ['required', 'string', 'min:5', 'max:1000']], ['motif.*' => __('admin.utilisateurs.erreurs.motif')])['motif'];

        return $this->executer(fn () => $this->workflow->rejeter($this->ficheExistante(), $request->user(), $motif), 'admin.parametrage.messages.rejete');
    }

    /** Paramètres confirmés remis en brouillon pour être corrigés ; le site public garde la version confirmée d'ici là. */
    public function reviser(Request $request): RedirectResponse
    {
        Gate::authorize(Droit::TranchesSaisir->value);

        return $this->executer(function () use ($request) {
            $fiche = $this->ficheExistante();
            if ($fiche->statut !== StatutGrilleTarifaire::Confirme) {
                throw new RegleGestionException(__('admin.grilles.regles.revision_confirmee'));
            }
            $fiche->forceFill(['statut' => StatutGrilleTarifaire::Brouillon])->save();
            $this->journal->ecrire($request->user(), 'investissement.revision', 'ParametresInvestissement', $fiche->id, ['statut' => 'CONFIRME'], ['statut' => 'BROUILLON']);
        }, 'admin.grilles.messages.revision');
    }

    /** @throws RegleGestionException */
    private function ficheExistante(): ParametresInvestissement
    {
        $fiche = ParametresInvestissement::fiche();

        return $fiche->exists ? $fiche : throw new RegleGestionException(__('admin.investissement.vides'));
    }

    private function executer(Closure $action, string $message): RedirectResponse
    {
        try {
            $action();
        } catch (RegleGestionException $e) {
            return redirect()->route('admin.investissement')->with('erreur', $e->getMessage());
        }

        return redirect()->route('admin.investissement')->with('succes', __($message));
    }
}
