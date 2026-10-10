<?php

namespace App\Http\Controllers\Compte;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Auth\OtpException;
use App\Services\Auth\OtpService;
use App\Support\Telephone;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

/**
 * Connexion du citoyen par numéro de téléphone + code reçu par SMS.
 */
class ConnexionController extends Controller
{
    use ValideTelephone;

    private const OBJET = 'connexion';

    public function __construct(private readonly OtpService $otp) {}

    public function create(Request $request): View
    {
        $telephone = $request->session()->get('connexion.telephone');

        return view('public.compte.connexion', [
            'etape' => $telephone ? 'code' : 'telephone',
            'telephone' => $telephone,
            'secondesRenvoi' => $telephone ? $this->otp->secondesAvantRenvoi($telephone, self::OBJET) : 0,
        ]);
    }

    public function envoyerCode(Request $request): RedirectResponse
    {
        $telephone = $this->telephoneValide($request);

        $compte = User::query()->where('telephone', $telephone)->first();

        if (! $compte) {
            return back()->withInput()
                ->withErrors(['telephone' => __('public.compte.connexion.inconnu')])
                ->with('compte.inconnu', true);
        }

        // Compte désactivé par le SUPER_ADMIN : aucun code n'est envoyé.
        if (! $compte->actif) {
            return back()->withInput()->withErrors(['telephone' => __('public.compte.connexion.desactive')]);
        }

        try {
            $this->otp->envoyer($telephone, self::OBJET);
        } catch (OtpException $e) {
            // Un code encore valable a déjà été envoyé : on passe simplement à sa saisie.
            if (! $this->otp->aUnCodeActif($telephone, self::OBJET)) {
                return back()->withInput()->withErrors(['telephone' => $e->getMessage()]);
            }
        }

        $request->session()->put('connexion.telephone', $telephone);

        return redirect()->route('connexion');
    }

    public function verifierCode(Request $request): RedirectResponse
    {
        $telephone = $request->session()->get('connexion.telephone');

        if (! $telephone) {
            return redirect()->route('connexion');
        }

        $code = $this->codeValide($request);

        try {
            $valide = $this->otp->verifier($telephone, self::OBJET, $code);
        } catch (OtpException $e) {
            return back()->withErrors(['code' => $e->getMessage()]);
        }

        if (! $valide) {
            return back()->withErrors(['code' => __('public.otp.erreurs.code_incorrect')]);
        }

        $user = User::query()->where('telephone', $telephone)->firstOrFail();

        Auth::login($user);
        $request->session()->regenerate();
        $request->session()->forget('connexion');

        return redirect()->intended(route('espace'));
    }

    public function renvoyer(Request $request): RedirectResponse
    {
        $telephone = $request->session()->get('connexion.telephone');

        if (! $telephone) {
            return redirect()->route('connexion');
        }

        try {
            $this->otp->envoyer($telephone, self::OBJET);
        } catch (OtpException $e) {
            return back()->withErrors(['code' => $e->getMessage()]);
        }

        return back();
    }

    public function modifier(Request $request): RedirectResponse
    {
        $telephone = $request->session()->pull('connexion.telephone');

        return redirect()->route('connexion')
            ->withInput(['telephone' => $telephone ? Telephone::formaterLocal($telephone) : '']);
    }
}
