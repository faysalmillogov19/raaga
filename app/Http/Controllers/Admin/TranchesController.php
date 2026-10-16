<?php

namespace App\Http\Controllers\Admin;

use App\Enums\Droit;
use App\Enums\MethodeAllocation;
use App\Enums\ModeSaisie;
use App\Enums\PayeurFrais;
use App\Enums\PublicAutorise;
use App\Enums\RegleArret;
use App\Enums\RegleSiEchec;
use App\Enums\StatutAvenant;
use App\Enums\StatutTranche;
use App\Enums\TypeFraisSouscription;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\TrancheParametresRequest;
use App\Models\Avenant;
use App\Models\MoyenPaiement;
use App\Models\ParametrageDecision;
use App\Models\Tranche;
use App\Services\Audit\Journal;
use App\Services\Backoffice\ConfirmationRenforcee;
use App\Services\Backoffice\RegleGestionException;
use App\Services\Invest\RaagaInvest;
use App\Services\Parametrage\WorkflowParametrage;
use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

/**
 * Tranches : le RÉGULATEUR saisit et soumet, le VALIDATEUR confirme ou rejette, puis ouvre,
 * prolonge (avenant) et clôture. Chaque action vérifie son droit côté serveur.
 */
class TranchesController extends Controller
{
    public function __construct(
        private readonly WorkflowParametrage $workflow,
        private readonly Journal $journal,
        private readonly ConfirmationRenforcee $confirmation,
    ) {}

    public function index(): View
    {
        Gate::authorize(Droit::TranchesVoir->value);

        return view('admin.tranches.index', [
            'tranches' => Tranche::query()->withCount('souscriptions')->orderByDesc('id')->paginate(25),
        ]);
    }

    public function create(): View
    {
        Gate::authorize(Droit::TranchesSaisir->value);

        return view('admin.tranches.formulaire', [
            'tranche' => new Tranche(self::valeursParDefaut()),
            'mode' => 'creation',
        ]);
    }

    public function store(TrancheParametresRequest $request): RedirectResponse
    {
        Gate::authorize(Droit::TranchesSaisir->value);

        $tranche = DB::transaction(function () use ($request) {
            $tranche = Tranche::create([
                ...$request->parametres(),
                'statut' => StatutTranche::Brouillon,
                'version' => 0,
                'cree_par' => $request->user()->id,
            ]);
            $this->journal->ecrire($request->user(), 'tranche.creee', 'Tranche', $tranche->id, null, ['nom' => $tranche->nom]);

            return $tranche;
        });

        return redirect()->route('admin.tranches.show', $tranche)->with('succes', __('admin.tranches.messages.creee'));
    }

    public function show(Request $request, Tranche $tranche): View
    {
        Gate::authorize(Droit::TranchesVoir->value);

        $versions = $tranche->versions()->with('auteur')->get();
        $soumise = $versions->last();
        $precedente = $versions->count() > 1 ? $versions->get($versions->count() - 2) : null;

        return view('admin.tranches.show', [
            'tranche' => $tranche,
            'versions' => $versions->reverse()->values(),
            'decisions' => ParametrageDecision::query()->where('objet_type', 'tranche')->where('objet_id', $tranche->id)
                ->with('decideur')->orderByDesc('id')->get(),
            'differentiel' => $tranche->estSoumis() && $soumise
                ? WorkflowParametrage::differentiel($precedente?->parametres ?? [], $soumise->parametres)
                : [],
            'premiereVersion' => $precedente === null,
            'avenants' => $tranche->avenants()->with(['auteur', 'decideur'])->get(),
            'totpExige' => ConfirmationRenforcee::totpExige($request->user()),
        ]);
    }

    public function edit(Tranche $tranche): View|RedirectResponse
    {
        Gate::authorize(Droit::TranchesSaisir->value);

        if (! $tranche->estModifiable()) {
            return redirect()->route('admin.tranches.show', $tranche)->with('erreur', __('admin.parametrage.regles.non_modifiable'));
        }

        return view('admin.tranches.formulaire', ['tranche' => $tranche, 'mode' => 'modification']);
    }

    public function update(TrancheParametresRequest $request, Tranche $tranche): RedirectResponse
    {
        Gate::authorize(Droit::TranchesSaisir->value);

        if (! $tranche->estModifiable()) {
            return redirect()->route('admin.tranches.show', $tranche)->with('erreur', __('admin.parametrage.regles.non_modifiable'));
        }

        $tranche->fill($request->parametres());
        $modifies = array_keys($tranche->getDirty());
        $tranche->save();

        if ($modifies) {
            $this->journal->ecrire($request->user(), 'tranche.modifiee', 'Tranche', $tranche->id, null, ['champs' => $modifies]);
        }

        return redirect()->route('admin.tranches.show', $tranche)->with('succes', __('admin.tranches.messages.enregistree'));
    }

    public function soumettre(Request $request, Tranche $tranche): RedirectResponse
    {
        Gate::authorize(Droit::TranchesSaisir->value);

        return $this->executer($tranche, fn () => $this->workflow->soumettre($tranche, $request->user()), 'admin.parametrage.messages.soumis');
    }

    public function confirmer(Request $request, Tranche $tranche): RedirectResponse
    {
        Gate::authorize(Droit::TranchesConfirmer->value);

        return $this->executer($tranche, fn () => $this->workflow->confirmer($tranche, $request->user()), 'admin.parametrage.messages.confirme');
    }

    public function rejeter(Request $request, Tranche $tranche): RedirectResponse
    {
        Gate::authorize(Droit::TranchesConfirmer->value);
        $motif = $this->motif($request);

        return $this->executer($tranche, fn () => $this->workflow->rejeter($tranche, $request->user(), $motif), 'admin.parametrage.messages.rejete');
    }

    public function reglement(Tranche $tranche): View
    {
        Gate::authorize(Droit::TranchesVoir->value);

        return view('admin.tranches.reglement', ['tranche' => $tranche]);
    }

    // ---------------------------------------------------------------- Avenants

    public function avenant(Tranche $tranche): View|RedirectResponse
    {
        Gate::authorize(Droit::TranchesSaisir->value);

        if ($tranche->champsAvenantAutorises() === []) {
            return redirect()->route('admin.tranches.show', $tranche)->with('erreur', __('admin.parametrage.regles.avenant_impossible'));
        }

        return view('admin.tranches.formulaire', ['tranche' => $tranche, 'mode' => 'avenant']);
    }

    public function proposerAvenant(Request $request, Tranche $tranche): RedirectResponse
    {
        Gate::authorize(Droit::TranchesSaisir->value);

        $motif = $this->motif($request);

        if ($tranche->champsAvenantAutorises() === ['date_cloture']) {
            $valeurs = $request->validate(
                ['date_cloture' => ['required', 'date', 'after:'.$tranche->date_ouverture->toDateTimeString()]],
                ['date_cloture.after' => __('admin.tranches.erreurs.cloture_apres_ouverture')],
                ['date_cloture' => __('admin.tranches.champs.date_cloture')],
            );
        } else {
            $valeurs = app(TrancheParametresRequest::class)->parametres();
        }

        return $this->executer($tranche, fn () => $this->workflow->proposerAvenant($tranche, $request->user(), $valeurs, $motif), 'admin.parametrage.messages.avenant_soumis');
    }

    public function confirmerAvenant(Request $request, Tranche $tranche, Avenant $avenant): RedirectResponse
    {
        Gate::authorize(Droit::TranchesConfirmer->value);
        $this->verifierAvenant($tranche, $avenant);

        return $this->executer($tranche, fn () => $this->workflow->confirmerAvenant($avenant, $request->user()), 'admin.parametrage.messages.avenant_confirme');
    }

    public function rejeterAvenant(Request $request, Tranche $tranche, Avenant $avenant): RedirectResponse
    {
        Gate::authorize(Droit::TranchesConfirmer->value);
        $this->verifierAvenant($tranche, $avenant);
        $motif = $this->motif($request);

        return $this->executer($tranche, fn () => $this->workflow->rejeterAvenant($avenant, $request->user(), $motif), 'admin.parametrage.messages.avenant_rejete');
    }

    // ---------------------------------------------------------------- Ouverture, clôture

    public function ouvrir(Request $request, Tranche $tranche): RedirectResponse
    {
        Gate::authorize(Droit::TranchesPiloter->value);

        return $this->executer($tranche, function () use ($request, $tranche) {
            $this->confirmation->verifier($request, $request->user());

            if ($tranche->statut !== StatutTranche::Confirmee) {
                throw new RegleGestionException(__('admin.tranches.regles.ouverture_confirmee'));
            }
            if ($tranche->avenants()->where('statut', StatutAvenant::EnAttente)->exists()) {
                throw new RegleGestionException(__('admin.parametrage.regles.avenant_en_cours'));
            }
            if (Tranche::query()->where('statut', StatutTranche::Ouverte)->exists()) {
                throw new RegleGestionException(__('admin.tranches.regles.une_seule_ouverte'));
            }

            $tranche->forceFill(['statut' => StatutTranche::Ouverte, 'ouverte_le' => now()])->save();
            $this->journal->ecrire($request->user(), 'tranche.ouverte', 'Tranche', $tranche->id, ['statut' => 'CONFIRMEE'], ['statut' => 'OUVERTE']);
        }, 'admin.tranches.messages.ouverte');
    }

    public function cloturer(Request $request, Tranche $tranche): RedirectResponse
    {
        Gate::authorize(Droit::TranchesPiloter->value);

        return $this->executer($tranche, function () use ($request, $tranche) {
            $this->confirmation->verifier($request, $request->user());

            if ($tranche->statut !== StatutTranche::Ouverte) {
                throw new RegleGestionException(__('admin.tranches.regles.cloture_ouverte'));
            }

            $tranche->forceFill(['statut' => StatutTranche::Cloturee, 'cloturee_le' => now()])->save();
            $this->journal->ecrire($request->user(), 'tranche.cloturee', 'Tranche', $tranche->id, ['statut' => 'OUVERTE'], ['statut' => 'CLOTUREE']);
        }, 'admin.tranches.messages.cloturee');
    }

    /**
     * Valeurs proposées à la création d'une tranche. Prix et minimum : valeur indicative et nombre minimum d'actions
     * des paramètres d'investissement confirmés (aucune valeur en dur) ; le RÉGULATEUR peut les ajuster, ils sont
     * figés quand le VALIDATEUR confirme la tranche.
     *
     * @return array<string, mixed>
     */
    public static function valeursParDefaut(): array
    {
        $parametres = RaagaInvest::parametres();

        return [
            'valeur_unitaire' => $parametres['valeur_action'] ?? null,
            'min_actions_par_souscription' => $parametres['minimum_actions'] ?? 1,
            'objectif_montant' => 3_000_000_000,
            'date_ouverture' => now()->addMonth()->startOfDay()->setTime(8, 0),
            'date_cloture' => now()->addMonths(3)->endOfDay()->setTime(23, 59),
            'publics_autorises' => [PublicAutorise::ResidentBf->value, PublicAutorise::Diaspora->value],
            'mode_saisie' => ModeSaisie::NbActions,
            'souscriptions_multiples' => true,
            'regle_arret' => RegleArret::ArretAObjectif,
            'methode_allocation' => MethodeAllocation::Prorata,
            'regle_si_echec' => RegleSiEchec::RemboursementIntegral,
            'delai_remboursement_jours' => 30,
            'frais_paiement_supportes_par' => PayeurFrais::Raaga,
            'frais_souscription_type' => TypeFraisSouscription::Aucun,
            'frais_souscription_valeur' => 0,
            'canaux_actifs' => MoyenPaiement::proposables()->pluck('code')->all(),
            'delai_paiement_heures' => 48,
        ];
    }

    private function verifierAvenant(Tranche $tranche, Avenant $avenant): void
    {
        abort_unless($avenant->avenantable_type === $tranche->getMorphClass() && $avenant->avenantable_id === $tranche->id, 404);
    }

    private function motif(Request $request): string
    {
        return $request->validate(
            ['motif' => ['required', 'string', 'min:5', 'max:1000']],
            ['motif.*' => __('admin.utilisateurs.erreurs.motif')],
        )['motif'];
    }

    private function executer(Tranche $tranche, Closure $action, string $message): RedirectResponse
    {
        try {
            $action();
        } catch (RegleGestionException $e) {
            return redirect()->route('admin.tranches.show', $tranche)->with('erreur', $e->getMessage());
        }

        return redirect()->route('admin.tranches.show', $tranche)->with('succes', __($message));
    }
}
