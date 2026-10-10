<?php

namespace App\Http\Controllers\Admin;

use App\Enums\Droit;
use App\Enums\ModeInscriptionA;
use App\Enums\StatutConvention;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ConventionParametresRequest;
use App\Models\Avenant;
use App\Models\Convention;
use App\Models\Institution;
use App\Models\ParametrageDecision;
use App\Services\Audit\Journal;
use App\Services\Backoffice\RegleGestionException;
use App\Services\Institutionnels\RegistreA;
use App\Services\Institutionnels\SignatureConvention;
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
 * Conventions de souscription d'actions A : le RÉGULATEUR saisit et soumet, le VALIDATEUR confirme
 * ou rejette (workflow commun, avenants), puis atteste la réception d'un original signé déposé.
 */
class ConventionsController extends Controller
{
    public const DISQUE = 'local';

    public function __construct(
        private readonly WorkflowParametrage $workflow,
        private readonly Journal $journal,
    ) {}

    public function index(): View
    {
        Gate::authorize(Droit::ConventionsVoir->value);

        return view('admin.conventions.index', [
            'conventions' => Convention::query()->with('institution')->latest('id')->paginate(25),
            'engage' => RegistreA::totalEngage(),
            'objectif' => RegistreA::objectifEngagements(),
        ]);
    }

    public function create(Request $request): View
    {
        Gate::authorize(Droit::ConventionsSaisir->value);

        return view('admin.conventions.formulaire', [
            'convention' => new Convention([
                'institution_id' => $request->integer('institution') ?: null,
                'mode_inscription' => ModeInscriptionA::Prorata,
                'echeancier' => [],
            ]),
            'institutions' => Institution::query()->orderBy('denomination')->get(),
            'mode' => 'creation',
        ]);
    }

    public function store(ConventionParametresRequest $request): RedirectResponse
    {
        Gate::authorize(Droit::ConventionsSaisir->value);

        $convention = DB::transaction(function () use ($request) {
            $convention = new Convention([
                ...$request->parametres(),
                'institution_id' => $request->integer('institution_id'),
                'statut' => StatutConvention::Brouillon,
                'version' => 0,
                'cree_par' => $request->user()->id,
            ]);
            $this->enregistrerPdf($convention, $request->file('pdf'));
            $convention->save();
            $this->journal->ecrire($request->user(), 'convention.creee', 'Convention', $convention->id, null, ['reference' => $convention->reference]);

            return $convention;
        });

        return redirect()->route('admin.conventions.show', $convention)->with('succes', __('institutionnel.bo.conventions.creee'));
    }

    public function show(Convention $convention): View
    {
        Gate::authorize(Droit::ConventionsVoir->value);

        $versions = $convention->versions()->with('auteur')->get();
        $soumise = $versions->last();
        $precedente = $versions->count() > 1 ? $versions->get($versions->count() - 2) : null;

        return view('admin.conventions.show', [
            'convention' => $convention->load(['institution', 'signataire', 'appels']),
            'versions' => $versions->reverse()->values(),
            'decisions' => ParametrageDecision::query()->where('objet_type', 'convention')->where('objet_id', $convention->id)
                ->with('decideur')->orderByDesc('id')->get(),
            'differentiel' => $convention->estSoumis() && $soumise
                ? WorkflowParametrage::differentiel($precedente?->parametres ?? [], $soumise->parametres)
                : [],
            'premiereVersion' => $precedente === null,
            'avenants' => $convention->avenants()->with(['auteur', 'decideur'])->get(),
        ]);
    }

    public function edit(Convention $convention): View|RedirectResponse
    {
        Gate::authorize(Droit::ConventionsSaisir->value);

        if (! $convention->estModifiable()) {
            return redirect()->route('admin.conventions.show', $convention)->with('erreur', __('admin.parametrage.regles.non_modifiable'));
        }

        return view('admin.conventions.formulaire', ['convention' => $convention, 'institutions' => collect(), 'mode' => 'modification']);
    }

    public function update(ConventionParametresRequest $request, Convention $convention): RedirectResponse
    {
        Gate::authorize(Droit::ConventionsSaisir->value);

        if (! $convention->estModifiable()) {
            return redirect()->route('admin.conventions.show', $convention)->with('erreur', __('admin.parametrage.regles.non_modifiable'));
        }

        $convention->fill($request->parametres());
        $this->enregistrerPdf($convention, $request->file('pdf'));
        $modifies = array_keys($convention->getDirty());
        $convention->save();

        if ($modifies) {
            $this->journal->ecrire($request->user(), 'convention.modifiee', 'Convention', $convention->id, null, ['champs' => $modifies]);
        }

        return redirect()->route('admin.conventions.show', $convention)->with('succes', __('institutionnel.bo.conventions.enregistree'));
    }

    public function soumettre(Request $request, Convention $convention): RedirectResponse
    {
        Gate::authorize(Droit::ConventionsSaisir->value);

        return $this->executer($convention, function () use ($request, $convention) {
            if (! $convention->pdf_chemin) {
                throw new RegleGestionException(__('institutionnel.regles.pdf_obligatoire'));
            }
            $this->workflow->soumettre($convention, $request->user());
        }, 'admin.parametrage.messages.soumis');
    }

    public function confirmer(Request $request, Convention $convention): RedirectResponse
    {
        Gate::authorize(Droit::ConventionsConfirmer->value);

        return $this->executer($convention, fn () => $this->workflow->confirmer($convention, $request->user()), 'admin.parametrage.messages.confirme');
    }

    public function rejeter(Request $request, Convention $convention): RedirectResponse
    {
        Gate::authorize(Droit::ConventionsConfirmer->value);
        $motif = $this->motif($request);

        return $this->executer($convention, fn () => $this->workflow->rejeter($convention, $request->user(), $motif), 'admin.parametrage.messages.rejete');
    }

    public function attesterReception(Request $request, Convention $convention): RedirectResponse
    {
        Gate::authorize(Droit::ConventionsConfirmer->value);

        return $this->executer($convention, fn () => app(SignatureConvention::class)->attesterReception($request->user(), $convention), 'institutionnel.bo.conventions.attestee');
    }

    public function pdf(Convention $convention): StreamedResponse
    {
        Gate::authorize(Droit::ConventionsVoir->value);
        abort_unless($convention->pdf_chemin, 404);

        return Storage::disk(self::DISQUE)->response($convention->pdf_chemin, 'convention-'.$convention->reference.'.pdf', ['Content-Type' => 'application/pdf']);
    }

    public function pdfSigne(Convention $convention): StreamedResponse
    {
        Gate::authorize(Droit::ConventionsVoir->value);
        abort_unless($convention->signature_pdf_chemin, 404);

        return Storage::disk(self::DISQUE)->response($convention->signature_pdf_chemin, 'convention-signee-'.$convention->reference.'.pdf', ['Content-Type' => 'application/pdf']);
    }

    // ---------------------------------------------------------------- Avenants

    public function avenant(Convention $convention): View|RedirectResponse
    {
        Gate::authorize(Droit::ConventionsSaisir->value);

        if ($convention->champsAvenantAutorises() === []) {
            return redirect()->route('admin.conventions.show', $convention)->with('erreur', __('admin.parametrage.regles.avenant_impossible'));
        }

        return view('admin.conventions.formulaire', ['convention' => $convention, 'institutions' => collect(), 'mode' => 'avenant']);
    }

    public function proposerAvenant(ConventionParametresRequest $request, Convention $convention): RedirectResponse
    {
        Gate::authorize(Droit::ConventionsSaisir->value);
        $motif = $this->motif($request);
        $valeurs = $request->parametres();

        return $this->executer($convention, fn () => $this->workflow->proposerAvenant($convention, $request->user(), $valeurs, $motif), 'admin.parametrage.messages.avenant_soumis');
    }

    public function confirmerAvenant(Request $request, Convention $convention, Avenant $avenant): RedirectResponse
    {
        Gate::authorize(Droit::ConventionsConfirmer->value);
        $this->verifierAvenant($convention, $avenant);

        return $this->executer($convention, fn () => $this->workflow->confirmerAvenant($avenant, $request->user()), 'admin.parametrage.messages.avenant_confirme');
    }

    public function rejeterAvenant(Request $request, Convention $convention, Avenant $avenant): RedirectResponse
    {
        Gate::authorize(Droit::ConventionsConfirmer->value);
        $this->verifierAvenant($convention, $avenant);
        $motif = $this->motif($request);

        return $this->executer($convention, fn () => $this->workflow->rejeterAvenant($avenant, $request->user(), $motif), 'admin.parametrage.messages.avenant_rejete');
    }

    /**
     * PDF de la convention stocké hors du dossier public ; son empreinte entre dans la version figée.
     */
    private function enregistrerPdf(Convention $convention, ?UploadedFile $pdf): void
    {
        if (! $pdf) {
            return;
        }

        $convention->fill([
            'pdf_chemin' => $pdf->storeAs('conventions', Str::uuid().'.pdf', self::DISQUE),
            'pdf_nom_original' => mb_substr($pdf->getClientOriginalName(), 0, 255),
            'pdf_empreinte' => hash_file('sha256', $pdf->getRealPath()),
        ]);
    }

    private function verifierAvenant(Convention $convention, Avenant $avenant): void
    {
        abort_unless($avenant->avenantable_type === $convention->getMorphClass() && $avenant->avenantable_id === $convention->id, 404);
    }

    private function motif(Request $request): string
    {
        return $request->validate(['motif' => ['required', 'string', 'min:5', 'max:1000']], ['motif.*' => __('admin.utilisateurs.erreurs.motif')])['motif'];
    }

    private function executer(Convention $convention, Closure $action, string $message): RedirectResponse
    {
        try {
            $action();
        } catch (RegleGestionException $e) {
            return redirect()->route('admin.conventions.show', $convention)->with('erreur', $e->getMessage());
        }

        return redirect()->route('admin.conventions.show', $convention)->with('succes', __($message));
    }
}
