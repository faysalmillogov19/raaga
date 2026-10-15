<?php

namespace App\Http\Controllers\Admin;

use App\Enums\ClasseActions;
use App\Enums\Droit;
use App\Enums\ModeCalculDividende;
use App\Enums\PayeurFrais;
use App\Enums\StatutDistribution;
use App\Enums\TraitementNonReclames;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\DistributionParametresRequest;
use App\Models\Avenant;
use App\Models\DistributionDividende;
use App\Models\ParametrageDecision;
use App\Services\Audit\Journal;
use App\Services\Backoffice\RegleGestionException;
use App\Services\Dividendes\SimulationDistribution;
use App\Services\Parametrage\WorkflowParametrage;
use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Paramètres de distribution des dividendes : le RÉGULATEUR saisit, simule et soumet ;
 * le VALIDATEUR confirme ou rejette. Chaque action vérifie son droit côté serveur.
 */
class DividendesController extends Controller
{
    private const DISQUE = 'local';

    public function __construct(
        private readonly WorkflowParametrage $workflow,
        private readonly Journal $journal,
        private readonly SimulationDistribution $simulation,
    ) {}

    public function index(): View
    {
        Gate::authorize(Droit::DividendesVoir->value);

        return view('admin.dividendes.index', [
            'distributions' => DistributionDividende::query()->orderByDesc('exercice')->paginate(25),
        ]);
    }

    public function create(): View
    {
        Gate::authorize(Droit::DividendesSaisir->value);

        return view('admin.dividendes.formulaire', [
            'distribution' => new DistributionDividende(self::valeursParDefaut()),
            'mode' => 'creation',
        ]);
    }

    public function store(DistributionParametresRequest $request): RedirectResponse
    {
        Gate::authorize(Droit::DividendesSaisir->value);

        $distribution = DB::transaction(function () use ($request) {
            $distribution = new DistributionDividende([
                ...$request->parametres(),
                'statut' => StatutDistribution::Brouillon,
                'version' => 0,
                'cree_par' => $request->user()->id,
            ]);
            $this->enregistrerPv($distribution, $request->file('pv'));
            $distribution->save();

            $this->journal->ecrire($request->user(), 'distribution.creee', 'DistributionDividende', $distribution->id, null, ['exercice' => $distribution->exercice]);

            return $distribution;
        });

        return redirect()->route('admin.dividendes.show', $distribution)->with('succes', __('admin.dividendes.messages.creee'));
    }

    public function show(DistributionDividende $distribution): View
    {
        Gate::authorize(Droit::DividendesVoir->value);

        $versions = $distribution->versions()->with('auteur')->get();
        $soumise = $versions->last();
        $precedente = $versions->count() > 1 ? $versions->get($versions->count() - 2) : null;

        return view('admin.dividendes.show', [
            'distribution' => $distribution,
            'versions' => $versions->reverse()->values(),
            'decisions' => ParametrageDecision::query()->where('objet_type', 'distribution')->where('objet_id', $distribution->id)
                ->with('decideur')->orderByDesc('id')->get(),
            'differentiel' => $distribution->estSoumis() && $soumise
                ? WorkflowParametrage::differentiel($precedente?->parametres ?? [], $soumise->parametres)
                : [],
            'premiereVersion' => $precedente === null,
            'avenants' => $distribution->avenants()->with(['auteur', 'decideur'])->get(),
        ]);
    }

    public function edit(DistributionDividende $distribution): View|RedirectResponse
    {
        Gate::authorize(Droit::DividendesSaisir->value);

        if (! $distribution->estModifiable()) {
            return redirect()->route('admin.dividendes.show', $distribution)->with('erreur', __('admin.parametrage.regles.non_modifiable'));
        }

        return view('admin.dividendes.formulaire', ['distribution' => $distribution, 'mode' => 'modification']);
    }

    public function update(DistributionParametresRequest $request, DistributionDividende $distribution): RedirectResponse
    {
        Gate::authorize(Droit::DividendesSaisir->value);

        if (! $distribution->estModifiable()) {
            return redirect()->route('admin.dividendes.show', $distribution)->with('erreur', __('admin.parametrage.regles.non_modifiable'));
        }

        $distribution->fill($request->parametres());
        $this->enregistrerPv($distribution, $request->file('pv'));
        $modifies = array_keys($distribution->getDirty());
        $distribution->save();

        if ($modifies) {
            $this->journal->ecrire($request->user(), 'distribution.modifiee', 'DistributionDividende', $distribution->id, null, ['champs' => $modifies]);
        }

        return redirect()->route('admin.dividendes.show', $distribution)->with('succes', __('admin.dividendes.messages.enregistree'));
    }

    /**
     * Soumission : PV de l'AG joint et attestation de conformité du montant obligatoires.
     */
    public function soumettre(Request $request, DistributionDividende $distribution): RedirectResponse
    {
        Gate::authorize(Droit::DividendesSaisir->value);

        $request->validate(
            ['montant_conforme_pv' => ['accepted']],
            ['montant_conforme_pv.accepted' => __('admin.dividendes.erreurs.attestation')],
        );

        return $this->executer($distribution, function () use ($request, $distribution) {
            if (! $distribution->pv_chemin || ! Storage::disk(self::DISQUE)->exists($distribution->pv_chemin)) {
                throw new RegleGestionException(__('admin.dividendes.erreurs.pv_obligatoire'));
            }

            $this->workflow->soumettre($distribution, $request->user());
        }, 'admin.parametrage.messages.soumis');
    }

    public function confirmer(Request $request, DistributionDividende $distribution): RedirectResponse
    {
        Gate::authorize(Droit::DividendesConfirmer->value);

        return $this->executer($distribution, fn () => $this->workflow->confirmer($distribution, $request->user()), 'admin.parametrage.messages.confirme');
    }

    public function rejeter(Request $request, DistributionDividende $distribution): RedirectResponse
    {
        Gate::authorize(Droit::DividendesConfirmer->value);
        $motif = $this->motif($request);

        return $this->executer($distribution, fn () => $this->workflow->rejeter($distribution, $request->user(), $motif), 'admin.parametrage.messages.rejete');
    }

    public function simulation(Request $request, DistributionDividende $distribution): View
    {
        Gate::authorize(Droit::DividendesVoir->value);
        Gate::authorize(Droit::SimulationsLancer->value);

        $source = $request->query('source') === SimulationDistribution::SOURCE_DEMO ? SimulationDistribution::SOURCE_DEMO : SimulationDistribution::SOURCE_REGISTRE;

        return view('admin.dividendes.simulation', [
            'distribution' => $distribution,
            'source' => $source,
            'resultat' => $this->simulation->simuler($distribution, $source),
        ]);
    }

    public function avis(DistributionDividende $distribution): View
    {
        Gate::authorize(Droit::DividendesVoir->value);

        return view('admin.dividendes.avis', ['distribution' => $distribution]);
    }

    public function pv(DistributionDividende $distribution): StreamedResponse
    {
        Gate::authorize(Droit::DividendesVoir->value);
        abort_unless($distribution->pv_chemin && Storage::disk(self::DISQUE)->exists($distribution->pv_chemin), 404);

        return Storage::disk(self::DISQUE)->download($distribution->pv_chemin, "PV-AG-{$distribution->exercice}.pdf");
    }

    // ---------------------------------------------------------------- Avenants

    public function avenant(DistributionDividende $distribution): View|RedirectResponse
    {
        Gate::authorize(Droit::DividendesSaisir->value);

        if ($distribution->champsAvenantAutorises() === []) {
            return redirect()->route('admin.dividendes.show', $distribution)->with('erreur', __('admin.parametrage.regles.avenant_impossible'));
        }

        return view('admin.dividendes.formulaire', ['distribution' => $distribution, 'mode' => 'avenant']);
    }

    public function proposerAvenant(Request $request, DistributionDividende $distribution): RedirectResponse
    {
        Gate::authorize(Droit::DividendesSaisir->value);
        $motif = $this->motif($request);
        $valeurs = app(DistributionParametresRequest::class)->parametres();

        return $this->executer($distribution, fn () => $this->workflow->proposerAvenant($distribution, $request->user(), $valeurs, $motif), 'admin.parametrage.messages.avenant_soumis');
    }

    public function confirmerAvenant(Request $request, DistributionDividende $distribution, Avenant $avenant): RedirectResponse
    {
        Gate::authorize(Droit::DividendesConfirmer->value);
        $this->verifierAvenant($distribution, $avenant);

        return $this->executer($distribution, fn () => $this->workflow->confirmerAvenant($avenant, $request->user()), 'admin.parametrage.messages.avenant_confirme');
    }

    public function rejeterAvenant(Request $request, DistributionDividende $distribution, Avenant $avenant): RedirectResponse
    {
        Gate::authorize(Droit::DividendesConfirmer->value);
        $this->verifierAvenant($distribution, $avenant);
        $motif = $this->motif($request);

        return $this->executer($distribution, fn () => $this->workflow->rejeterAvenant($avenant, $request->user(), $motif), 'admin.parametrage.messages.avenant_rejete');
    }

    /**
     * @return array<string, mixed>
     */
    public static function valeursParDefaut(): array
    {
        return [
            'exercice' => (int) now()->subYear()->format('Y'),
            'classe_actions' => ClasseActions::B,
            'mode_calcul' => ModeCalculDividende::MontantGlobal,
            'taux_irvm_pb' => 1250,
            'frais_supportes_par' => PayeurFrais::Raaga,
            'seuil_minimal' => 500,
            'canaux_autorises' => DistributionParametresRequest::canaux()->pluck('code')->all(),
            'canal_defaut' => DistributionParametresRequest::canaux()->first(),
            'tentatives' => 3,
            'delai_tentatives_heures' => 24,
            'conservation_non_reclames_mois' => 60,
            'traitement_non_reclames' => TraitementNonReclames::Consignation,
        ];
    }

    /**
     * PV de l'AG stocké hors du dossier public ; son empreinte entre dans la version figée.
     */
    private function enregistrerPv(DistributionDividende $distribution, ?UploadedFile $pv): void
    {
        if (! $pv) {
            return;
        }

        $chemin = $pv->storeAs('pv-ag', Str::uuid().'.pdf', self::DISQUE);

        $distribution->fill([
            'pv_chemin' => $chemin,
            'pv_nom_original' => mb_substr($pv->getClientOriginalName(), 0, 255),
            'pv_empreinte' => hash_file('sha256', $pv->getRealPath()),
        ]);
    }

    private function verifierAvenant(DistributionDividende $distribution, Avenant $avenant): void
    {
        abort_unless($avenant->avenantable_type === $distribution->getMorphClass() && $avenant->avenantable_id === $distribution->id, 404);
    }

    private function motif(Request $request): string
    {
        return $request->validate(
            ['motif' => ['required', 'string', 'min:5', 'max:1000']],
            ['motif.*' => __('admin.utilisateurs.erreurs.motif')],
        )['motif'];
    }

    private function executer(DistributionDividende $distribution, Closure $action, string $message): RedirectResponse
    {
        try {
            $action();
        } catch (RegleGestionException $e) {
            return redirect()->route('admin.dividendes.show', $distribution)->with('erreur', $e->getMessage());
        }

        return redirect()->route('admin.dividendes.show', $distribution)->with('succes', __($message));
    }
}
