<?php

namespace App\Http\Controllers\Admin;

use App\Enums\Droit;
use App\Enums\StatutGrilleTarifaire;
use App\Http\Controllers\Controller;
use App\Models\Duree;
use App\Models\ParametrageDecision;
use App\Models\ParametresPaiements;
use App\Services\Audit\Journal;
use App\Services\Backoffice\RegleGestionException;
use App\Services\Parametrage\WorkflowParametrage;
use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Délais de paiement (caution, complément d'un paiement incomplet, avance), chacun en valeur + durée obligatoires, la
 * valeur en secondes étant calculée et stockée : RÉGULATEUR saisit et soumet, VALIDATEUR confirme ou rejette
 * (« À valider › Données financières ») ; le SUPER_ADMIN les met à jour directement, sans validation. Valeurs par
 * défaut (72 heures, 48 heures, 30 jours) tant que rien n'est confirmé.
 */
class ParametresPaiementsController extends Controller
{
    public function __construct(private readonly WorkflowParametrage $workflow, private readonly Journal $journal) {}

    public function show(): View
    {
        Gate::authorize(Droit::ModalitesPaiementVoir->value);
        $fiche = ParametresPaiements::fiche();
        $versions = $fiche->exists ? $fiche->versions()->with('auteur')->get() : collect();
        $precedente = $versions->count() > 1 ? $versions->get($versions->count() - 2) : null;

        return view('admin.parametres-paiements.show', [
            'fiche' => $fiche,
            'enVigueur' => collect(array_keys(ParametresPaiements::DELAIS))->mapWithKeys(fn (string $d) => [$d => ParametresPaiements::libelleEnVigueur($d)])->all(),
            'durees' => Duree::toutes(),
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
        Gate::authorize(Droit::MoyensPaiementGerer->value);
        $donnees = $this->donnees($request);
        $fiche = ParametresPaiements::fiche();

        return $this->executer(function () use ($request, $fiche, $donnees) {
            if ($fiche->exists && ! $fiche->estModifiable()) {
                throw new RegleGestionException(__('admin.parametrage.regles.non_modifiable'));
            }
            $avant = $fiche->only(ParametresPaiements::PARAMETRES);
            $fiche->fill($donnees)->save();
            $this->journal->ecrire($request->user(), 'delais.modifie', 'ParametresPaiements', $fiche->id, $avant, $donnees);
        }, 'admin.delais.messages.enregistres');
    }

    /**
     * SUPER_ADMIN : délais créés par le seeder mis à jour directement, appliqués tout de suite sans VALIDATEUR (version
     * confirmée à son nom, journal). Refusé si une proposition du RÉGULATEUR attend la décision du VALIDATEUR.
     */
    public function miseAJourDirecte(Request $request): RedirectResponse
    {
        Gate::authorize(Droit::InstallationMettreAJour->value);
        $donnees = $this->donnees($request);
        $fiche = ParametresPaiements::fiche();

        return $this->executer(function () use ($request, $fiche, $donnees) {
            if ($fiche->exists && $fiche->estSoumis()) {
                throw new RegleGestionException(__('admin.parametrage.regles.direct_en_attente'));
            }
            $avant = $fiche->only(ParametresPaiements::PARAMETRES);
            $fiche->fill($donnees)->save();
            $this->journal->ecrire($request->user(), 'delais.modifie', 'ParametresPaiements', $fiche->id, $avant, $donnees);
            $this->workflow->appliquerDirectement($fiche, $request->user());
        }, 'admin.parametrage.messages.mis_a_jour');
    }

    /**
     * SUPER_ADMIN : libellés d'une durée créée par le seeder (seconde, minute, heure, jour), appliqués directement. Sa
     * valeur en secondes ne change jamais : les délais déjà enregistrés en dépendent.
     */
    public function dureeDirecte(Request $request, Duree $duree): RedirectResponse
    {
        Gate::authorize(Droit::InstallationMettreAJour->value);
        $donnees = $request->validate([
            'libelle' => ['required', 'string', 'max:30'],
            'libelle_pluriel' => ['required', 'string', 'max:30'],
        ], [], __('admin.delais.durees.attributs'));

        return $this->executer(function () use ($request, $duree, $donnees) {
            $avant = $duree->only(array_keys($donnees));
            $duree->fill($donnees);
            if (! $duree->isDirty()) {
                return;
            }
            $duree->save();
            $this->journal->ecrire($request->user(), 'duree.mise_a_jour_directe', 'Duree', $duree->id, $avant, $donnees);
        }, 'admin.parametrage.messages.mis_a_jour');
    }

    /**
     * Délais saisis, convertis en secondes (calculées et stockées) ; un délai ne dépasse pas un an.
     *
     * @return array<string, int>
     */
    private function donnees(Request $request): array
    {
        $regles = [];
        foreach (array_keys(ParametresPaiements::DELAIS) as $d) {
            $regles['delai_'.$d.'_valeur'] = ['required', 'integer', 'min:1', 'max:999999'];
            $regles['delai_'.$d.'_duree'] = ['required', 'string', Rule::in(Duree::toutes()->pluck('code')->all())];
        }
        $saisie = $request->validate($regles, [], __('admin.delais.champs'));

        // Valeur convertie en secondes, calculée et stockée ; un délai ne dépasse pas un an.
        $donnees = [];
        foreach (array_keys(ParametresPaiements::DELAIS) as $d) {
            $duree = Duree::parCode($saisie['delai_'.$d.'_duree']);
            $secondes = $duree->enSecondes((int) $saisie['delai_'.$d.'_valeur']);
            if ($secondes > ParametresPaiements::SECONDES_MAX) {
                throw ValidationException::withMessages(['delai_'.$d.'_valeur' => __('admin.delais.erreurs.trop_long')]);
            }
            $donnees += ['delai_'.$d.'_valeur' => (int) $saisie['delai_'.$d.'_valeur'], 'delai_'.$d.'_duree_id' => $duree->id, 'delai_'.$d.'_secondes' => $secondes];
        }

        return $donnees;
    }

    public function soumettre(Request $request): RedirectResponse
    {
        Gate::authorize(Droit::MoyensPaiementGerer->value);

        return $this->executer(fn () => $this->workflow->soumettre($this->ficheExistante(), $request->user()), 'admin.parametrage.messages.soumis');
    }

    public function confirmer(Request $request): RedirectResponse
    {
        Gate::authorize(Droit::MoyensPaiementConfirmer->value);

        return $this->executer(fn () => $this->workflow->confirmer($this->ficheExistante(), $request->user()), 'admin.parametrage.messages.confirme');
    }

    public function rejeter(Request $request): RedirectResponse
    {
        Gate::authorize(Droit::MoyensPaiementConfirmer->value);
        $motif = $request->validate(['motif' => ['required', 'string', 'min:5', 'max:1000']], ['motif.*' => __('admin.utilisateurs.erreurs.motif')])['motif'];

        return $this->executer(fn () => $this->workflow->rejeter($this->ficheExistante(), $request->user(), $motif), 'admin.parametrage.messages.rejete');
    }

    /** Paramètres confirmés remis en brouillon pour être corrigés ; le site public garde la version confirmée d'ici là. */
    public function reviser(Request $request): RedirectResponse
    {
        Gate::authorize(Droit::MoyensPaiementGerer->value);

        return $this->executer(function () use ($request) {
            $fiche = $this->ficheExistante();
            if ($fiche->statut !== StatutGrilleTarifaire::Confirme) {
                throw new RegleGestionException(__('admin.grilles.regles.revision_confirmee'));
            }
            $fiche->forceFill(['statut' => StatutGrilleTarifaire::Brouillon])->save();
            $this->journal->ecrire($request->user(), 'delais.revision', 'ParametresPaiements', $fiche->id, ['statut' => 'CONFIRME'], ['statut' => 'BROUILLON']);
        }, 'admin.grilles.messages.revision');
    }

    /** @throws RegleGestionException */
    private function ficheExistante(): ParametresPaiements
    {
        $fiche = ParametresPaiements::fiche();

        return $fiche->exists ? $fiche : throw new RegleGestionException(__('admin.delais.vides'));
    }

    /** Retour à la page des délais (ou à « À valider » pour le VALIDATEUR, sans l'onglet « Modalités de paiement »). */
    private function executer(Closure $action, string $message): RedirectResponse
    {
        $page = Gate::allows(Droit::ModalitesPaiementVoir->value) ? redirect()->route('admin.parametres-paiements') : redirect()->route('admin.a-valider');
        try {
            $action();
        } catch (RegleGestionException $e) {
            return $page->with('erreur', $e->getMessage());
        }

        return $page->avecSucces($message);
    }
}
