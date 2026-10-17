<?php

namespace App\Http\Controllers\Institutionnel;

use App\Http\Controllers\Admin\ConventionsController;
use App\Models\Convention;
use App\Services\Backoffice\RegleGestionException;
use App\Services\Institutionnels\SignatureConvention;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * « Ma convention » : convention confirmée, échéancier, signature (signataire seulement), avenants.
 * Une convention en brouillon ou en attente de confirmation n'est pas visible de l'institution.
 */
class ConventionController extends Controleur
{
    public function __construct(private readonly SignatureConvention $signature) {}

    public function show(): View
    {
        $convention = $this->institution()->conventions()->where('statut', 'CONFIRMEE')->with(['appels', 'signataire'])->first();

        return view('institutionnel.convention', [
            'institution' => $this->institution(),
            'convention' => $convention,
            'avenants' => $convention?->avenants()->where('statut', 'APPROUVE')->get() ?? collect(),
        ]);
    }

    public function pdf(Convention $convention): StreamedResponse
    {
        $this->verifierAppartenance($convention);
        abort_unless($convention->estConfirmee() && $convention->pdf_chemin, 404);

        return Storage::disk(ConventionsController::DISQUE)->response($convention->pdf_chemin, 'convention-'.$convention->reference.'.pdf', ['Content-Type' => 'application/pdf']);
    }

    public function signer(Request $request, Convention $convention): RedirectResponse
    {
        $this->verifierAppartenance($convention);
        $this->exigerSignataire();
        $request->validate(['code' => ['required', 'string', 'max:10']], ['code.*' => __('admin.connexion.erreurs.code')]);

        return $this->executer(fn () => $this->signature->signer($request->user(), $convention, (string) $request->input('code')), 'institutionnel.convention.signee_message');
    }

    public function deposer(Request $request, Convention $convention): RedirectResponse
    {
        $this->verifierAppartenance($convention);
        $this->exigerSignataire();
        $request->validate(['convention_signee' => ['required', 'file', 'mimes:pdf', 'max:20480']], [], ['convention_signee' => __('institutionnel.convention.deposer')]);

        return $this->executer(fn () => $this->signature->deposerSignee($request->user(), $convention, $request->file('convention_signee')), 'institutionnel.convention.deposee_message');
    }

    private function executer(\Closure $action, string $message): RedirectResponse
    {
        try {
            $action();
        } catch (RegleGestionException $e) {
            return redirect()->route('institutionnel.convention')->with('erreur', $e->getMessage());
        }

        return redirect()->route('institutionnel.convention')->avecSucces($message);
    }
}
