<?php

namespace App\Http\Controllers\Admin;

use App\Enums\Droit;
use App\Enums\StatutDocumentKyc;
use App\Http\Controllers\Controller;
use App\Models\Institution;
use App\Models\InstitutionDocument;
use App\Services\Backoffice\RegleGestionException;
use App\Services\Institutionnels\DossierKyb;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * « Dossiers institutionnels » : file de vérification des dossiers KYB. Le VALIDATEUR valide ou
 * rejette chaque pièce (motif obligatoire) ; le RÉGULATEUR consulte.
 */
class KybController extends Controller
{
    public function __construct(private readonly DossierKyb $dossier) {}

    public function index(): View
    {
        Gate::authorize(Droit::KybVoir->value);

        $institutions = Institution::query()->with('documents')->orderBy('denomination')->get()
            ->map(fn (Institution $i) => [
                'institution' => $i,
                'a_verifier' => $i->piecesEnVigueur()->where('statut', StatutDocumentKyc::EnAttente)->count(),
                'validees' => $i->piecesEnVigueur()->where('statut', StatutDocumentKyc::Valide)->count(),
            ])
            ->sortByDesc('a_verifier')->values();

        return view('admin.kyb.index', ['dossiers' => $institutions]);
    }

    public function show(Institution $institution): View
    {
        Gate::authorize(Droit::KybVoir->value);

        return view('admin.kyb.show', [
            'institution' => $institution,
            'pieces' => $institution->piecesEnVigueur(),
            'historique' => $institution->documents()->with(['deposant', 'verificateur'])->get(),
        ]);
    }

    public function telecharger(InstitutionDocument $document): StreamedResponse
    {
        Gate::authorize(Droit::KybVoir->value);

        return Storage::disk(DossierKyb::DISQUE)->response($document->chemin, $document->type->value.'-'.$document->institution_id.'.pdf', [
            'Content-Type' => 'application/pdf',
        ]);
    }

    public function valider(Request $request, InstitutionDocument $document): RedirectResponse
    {
        Gate::authorize(Droit::KybVerifier->value);

        return $this->executer(fn () => $this->dossier->valider($request->user(), $document), $document, 'institutionnel.bo.kyb.validee');
    }

    public function rejeter(Request $request, InstitutionDocument $document): RedirectResponse
    {
        Gate::authorize(Droit::KybVerifier->value);
        $motif = $request->validate(['motif' => ['required', 'string', 'min:5', 'max:500']], ['motif.*' => __('admin.utilisateurs.erreurs.motif')])['motif'];

        return $this->executer(fn () => $this->dossier->rejeter($request->user(), $document, $motif), $document, 'institutionnel.bo.kyb.rejetee');
    }

    private function executer(\Closure $action, InstitutionDocument $document, string $message): RedirectResponse
    {
        try {
            $action();
        } catch (RegleGestionException $e) {
            return redirect()->route('admin.kyb.show', $document->institution_id)->with('erreur', $e->getMessage());
        }

        return redirect()->route('admin.kyb.show', $document->institution_id)->with('succes', __($message));
    }
}
