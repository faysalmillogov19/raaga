<?php

namespace App\Http\Controllers\Admin;

use App\Enums\Droit;
use App\Enums\StatutDeclarationVirement;
use App\Http\Controllers\Controller;
use App\Models\AppelFonds;
use App\Models\DeclarationVirement;
use App\Services\Backoffice\RegleGestionException;
use App\Services\Documents\GenerateurPdf;
use App\Services\Institutionnels\AppelsDeFonds;
use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Appels de fonds des actions A : file des virements déclarés à rapprocher avec le relevé du
 * compte séquestre (VALIDATEUR), suivi des appels (RÉGULATEUR et VALIDATEUR en lecture).
 */
class AppelsFondsController extends Controller
{
    public function __construct(private readonly AppelsDeFonds $appels) {}

    public function index(Request $request): View
    {
        Gate::authorize(Droit::AppelsFondsVoir->value);

        return view('admin.appels-fonds.index', [
            'aRapprocher' => DeclarationVirement::query()->where('statut', StatutDeclarationVirement::Declaree)
                ->with(['appel.institution', 'appel.convention', 'declarant'])->oldest('id')->get(),
            'appels' => AppelFonds::query()->with(['institution', 'convention'])
                ->when($request->filled('statut'), fn ($q) => $q->where('statut', $request->query('statut')))
                ->orderBy('echeance')->paginate(30)->withQueryString(),
        ]);
    }

    public function show(AppelFonds $appel): View
    {
        Gate::authorize(Droit::AppelsFondsVoir->value);

        return view('admin.appels-fonds.show', [
            'appel' => $appel->load(['institution', 'convention', 'declarations.declarant']),
        ]);
    }

    public function avis(AppelFonds $appel, GenerateurPdf $pdf): Response
    {
        Gate::authorize(Droit::AppelsFondsVoir->value);

        return response($pdf->rendre('documents.avis-appel-fonds', ['appel' => $appel]), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="avis-appel-de-fonds-'.$appel->reference_virement.'.pdf"',
        ]);
    }

    public function avisVirement(DeclarationVirement $declaration): StreamedResponse
    {
        Gate::authorize(Droit::AppelsFondsVoir->value);
        abort_unless($declaration->avis_chemin, 404);

        return Storage::disk(AppelsDeFonds::DISQUE)->response($declaration->avis_chemin, 'avis-virement-'.$declaration->id.'.pdf', ['Content-Type' => 'application/pdf']);
    }

    public function rapprocher(Request $request, DeclarationVirement $declaration): RedirectResponse
    {
        Gate::authorize(Droit::AppelsFondsRapprocher->value);

        $donnees = $request->validate([
            'montant_recu' => ['required', 'integer', 'min:1'],
            'recu_le' => ['required', 'date', 'before_or_equal:today'],
            'reference_releve' => ['required', 'string', 'max:80'],
        ], [], [
            'montant_recu' => __('institutionnel.bo.appels.montant_recu'),
            'recu_le' => __('institutionnel.bo.appels.recu_le'),
            'reference_releve' => __('institutionnel.bo.appels.reference_releve'),
        ]);

        return $this->executer(fn () => $this->appels->rapprocher($request->user(), $declaration, (int) $donnees['montant_recu'], $donnees['recu_le'], $donnees['reference_releve']),
            $declaration, 'institutionnel.bo.appels.rapproche');
    }

    public function rejeter(Request $request, DeclarationVirement $declaration): RedirectResponse
    {
        Gate::authorize(Droit::AppelsFondsRapprocher->value);
        $motif = $request->validate(['motif' => ['required', 'string', 'min:5', 'max:500']], ['motif.*' => __('admin.utilisateurs.erreurs.motif')])['motif'];

        return $this->executer(fn () => $this->appels->rejeter($request->user(), $declaration, $motif), $declaration, 'institutionnel.bo.appels.rejete');
    }

    private function executer(Closure $action, DeclarationVirement $declaration, string $message): RedirectResponse
    {
        try {
            $action();
        } catch (RegleGestionException $e) {
            return redirect()->route('admin.appels-fonds.show', $declaration->appel_fonds_id)->with('erreur', $e->getMessage());
        }

        return redirect()->route('admin.appels-fonds.show', $declaration->appel_fonds_id)->with('succes', __($message));
    }
}
