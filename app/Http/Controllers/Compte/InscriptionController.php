<?php

namespace App\Http\Controllers\Compte;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Auth\OtpException;
use App\Services\Auth\OtpService;
use App\Services\Compte\CreationCompte;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

/**
 * Création d'un compte citoyen en 3 étapes : téléphone → code SMS → identité.
 * L'état du parcours est conservé en session (clé « inscription »).
 */
class InscriptionController extends Controller
{
    use ValideTelephone;

    private const OBJET = 'inscription';

    private const CHAMPS_IDENTITE = CreationCompte::CHAMPS_IDENTITE;

    public function __construct(private readonly OtpService $otp, private readonly CreationCompte $creation) {}

    public function create(Request $request): View|RedirectResponse
    {
        if (Auth::check()) {
            return $request->session()->get('inscription.succes')
                ? view('public.compte.inscription', ['etape' => 'succes'])
                : redirect()->route('espace');
        }

        $session = $request->session()->get('inscription', []);
        $etape = $session['etape'] ?? 'telephone';
        $telephone = $session['telephone'] ?? null;

        return view('public.compte.inscription', [
            'etape' => $etape,
            'telephone' => $telephone,
            'saisie' => $session['saisie'] ?? [],
            'secondesRenvoi' => $etape === 'code' && $telephone ? $this->otp->secondesAvantRenvoi($telephone, self::OBJET) : 0,
            'pays' => config('raaga.inscription.pays'),
            'regions' => config('raaga.inscription.regions'),
        ]);
    }

    public function envoyerCode(Request $request): RedirectResponse
    {
        $telephone = $this->telephoneValide($request);

        if (User::query()->where('telephone', $telephone)->exists()) {
            return back()->withInput()
                ->withErrors(['telephone' => __('public.compte.inscription.existe')])
                ->with('compte.existe', true);
        }

        // Numéro déjà vérifié dans ce parcours (retour arrière) : on reprend directement à l'identité.
        if ($request->session()->get('inscription.verifie') === $telephone) {
            $request->session()->put('inscription.etape', 'identite');

            return redirect()->route('inscription');
        }

        try {
            $this->otp->envoyer($telephone, self::OBJET);
        } catch (OtpException $e) {
            // Un code encore valable a déjà été envoyé : on passe simplement à sa saisie.
            if (! $this->otp->aUnCodeActif($telephone, self::OBJET)) {
                return back()->withInput()->withErrors(['telephone' => $e->getMessage()]);
            }
        }

        $request->session()->put('inscription.telephone', $telephone);
        $request->session()->put('inscription.etape', 'code');

        return redirect()->route('inscription');
    }

    public function verifierCode(Request $request): RedirectResponse
    {
        $telephone = $request->session()->get('inscription.telephone');

        if (! $telephone) {
            return redirect()->route('inscription');
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

        $request->session()->put('inscription.verifie', $telephone);
        $request->session()->put('inscription.etape', 'identite');

        return redirect()->route('inscription');
    }

    public function renvoyer(Request $request): RedirectResponse
    {
        $telephone = $request->session()->get('inscription.telephone');

        if (! $telephone) {
            return redirect()->route('inscription');
        }

        try {
            $this->otp->envoyer($telephone, self::OBJET);
        } catch (OtpException $e) {
            return back()->withErrors(['code' => $e->getMessage()]);
        }

        return back();
    }

    /**
     * Revient à l'étape du téléphone en conservant la saisie (numéro et identité).
     */
    public function retour(Request $request): RedirectResponse
    {
        if ($request->hasAny(self::CHAMPS_IDENTITE)) {
            $request->session()->put('inscription.saisie', $request->only(self::CHAMPS_IDENTITE));
        }

        $request->session()->put('inscription.etape', 'telephone');

        return redirect()->route('inscription');
    }

    public function store(Request $request): RedirectResponse
    {
        $telephone = $request->session()->get('inscription.verifie');

        if (! $telephone) {
            $request->session()->put('inscription.etape', 'telephone');

            return redirect()->route('inscription')
                ->withErrors(['telephone' => __('public.compte.inscription.erreurs.session')]);
        }

        $request->session()->put('inscription.saisie', $request->only(self::CHAMPS_IDENTITE));

        $donnees = $request->validate(CreationCompte::regles(), CreationCompte::messages());
        $user = $this->creation->creer($donnees, $telephone, $request->ip(), $request->userAgent());

        Auth::login($user);
        $request->session()->regenerate();
        $request->session()->forget('inscription');
        $request->session()->put('inscription.succes', true);

        return redirect()->route('inscription');
    }
}
