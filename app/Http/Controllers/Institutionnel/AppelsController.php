<?php

namespace App\Http\Controllers\Institutionnel;

use App\Models\AppelFonds;
use App\Services\Backoffice\RegleGestionException;
use App\Services\Documents\GenerateurPdf;
use App\Services\Institutionnels\AppelsDeFonds;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\View\View;

/**
 * « Appels de fonds » : liste, avis PDF, déclaration de virement (signataire seulement), statut.
 */
class AppelsController extends Controleur
{
    public function __construct(private readonly AppelsDeFonds $appels) {}

    public function index(): View
    {
        return view('institutionnel.appels.index', [
            'appels' => AppelFonds::query()->where('institution_id', $this->institution()->id)->with('convention')->orderBy('echeance')->get(),
        ]);
    }

    public function show(AppelFonds $appel): View
    {
        $this->verifierAppartenance($appel);

        return view('institutionnel.appels.show', ['appel' => $appel->load(['convention', 'declarations.declarant'])]);
    }

    public function avis(AppelFonds $appel, GenerateurPdf $pdf): Response
    {
        $this->verifierAppartenance($appel);

        return response($pdf->rendre('documents.avis-appel-fonds', ['appel' => $appel]), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="avis-appel-de-fonds-'.$appel->reference_virement.'.pdf"',
        ]);
    }

    public function declarer(Request $request, AppelFonds $appel): RedirectResponse
    {
        $this->verifierAppartenance($appel);
        $this->exigerSignataire();

        $donnees = $request->validate([
            'date_virement' => ['required', 'date', 'before_or_equal:today'],
            'montant' => ['required', 'integer', 'min:1'],
            'reference' => ['required', 'string', 'max:60'],
            'avis' => ['nullable', 'file', 'mimes:pdf', 'max:10240'],
        ], [], [
            'date_virement' => __('institutionnel.appels.date_virement'),
            'montant' => __('institutionnel.appels.montant'),
            'reference' => __('institutionnel.appels.reference'),
            'avis' => __('institutionnel.appels.avis_virement'),
        ]);

        try {
            $this->appels->declarer($request->user(), $appel, collect($donnees)->except('avis')->all(), $request->file('avis'));
        } catch (RegleGestionException $e) {
            return redirect()->route('institutionnel.appels.show', $appel)->with('erreur', $e->getMessage());
        }

        return redirect()->route('institutionnel.appels.show', $appel)->with('succes', __('institutionnel.appels.declare'));
    }
}
