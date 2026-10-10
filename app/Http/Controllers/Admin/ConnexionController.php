<?php

namespace App\Http\Controllers\Admin;

use App\Enums\Espace;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Backoffice\DoubleAuthentification;
use App\Services\Backoffice\JournalConnexions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\View\View;

/**
 * Connexion aux espaces sécurisés (back-office, espace institutionnel) : e-mail + mot de passe,
 * puis double authentification TOTP (ou enrôlement à la première connexion). L'utilisateur n'est
 * réellement connecté qu'après la seconde étape. L'espace vient de la route (« espace »).
 */
class ConnexionController extends Controller
{
    /** Durée de validité de l'étape intermédiaire (mot de passe validé, TOTP attendu). */
    private const ATTENTE_MINUTES = 5;

    public function __construct(
        private readonly DoubleAuthentification $totp,
        private readonly JournalConnexions $journal,
    ) {}

    public function create(Request $request): View|RedirectResponse
    {
        $espace = self::espace($request);

        if ($request->user() && $espace->autorise($request->user()) && $request->session()->get($espace->cleSession().'.totp_ok')) {
            return redirect()->route($espace->accueil());
        }

        return view('admin.connexion.identifiants', ['espace' => $espace]);
    }

    /** Espace de la route courante (back-office par défaut). */
    public static function espace(Request $request): Espace
    {
        return Espace::from($request->route()?->defaults['espace'] ?? Espace::Backoffice->value);
    }

    public function store(Request $request): RedirectResponse
    {
        $request->validate(
            ['email' => ['required', 'email'], 'password' => ['required', 'string']],
            ['email.*' => __('admin.connexion.erreurs.email'), 'password.required' => __('admin.connexion.erreurs.mot_de_passe')],
        );

        $email = mb_strtolower(trim($request->input('email')));
        $user = User::query()->whereRaw('LOWER(email) = ?', [$email])->first();
        $neutre = ['email' => __('admin.connexion.erreurs.identifiants')];

        if ($user?->estVerrouille()) {
            $this->journal->echec($request, $user, $email, 'mot_de_passe', 'verrouille');

            return back()->onlyInput('email')->withErrors(['email' => $this->messageVerrouillage($user)]);
        }

        if ($user === null || ! $user->password || ! Hash::check($request->input('password'), $user->password)) {
            $this->journal->echec($request, $user, $email, 'mot_de_passe', $user ? 'mot_de_passe' : 'compte_inconnu');

            return $user?->refresh()->estVerrouille()
                ? back()->onlyInput('email')->withErrors(['email' => $this->messageVerrouillage($user)])
                : back()->onlyInput('email')->withErrors($neutre);
        }

        $espace = self::espace($request);

        // Compte valide mais hors de cet espace ou désactivé : même message neutre.
        if (! $espace->autorise($user) || ! $user->actif) {
            $this->journal->echec($request, $user, $email, 'mot_de_passe', $user->actif ? 'role' : 'desactive');

            return back()->onlyInput('email')->withErrors($neutre);
        }

        $request->session()->put($espace->cleSession().'.en_attente', ['id' => $user->id, 'expire' => now()->addMinutes(self::ATTENTE_MINUTES)->timestamp]);

        if ($user->totpActive()) {
            return redirect()->route($espace->route('connexion.totp'));
        }

        if ($this->totpFacultatif()) {
            return $this->terminer($request, $user, 'mot_de_passe');
        }

        return redirect()->route($espace->route('connexion.enrolement'));
    }

    public function totp(Request $request): View|RedirectResponse
    {
        return $this->utilisateurEnAttente($request)
            ? view('admin.connexion.totp', ['espace' => self::espace($request)])
            : redirect()->route(self::espace($request)->route('connexion'));
    }

    public function verifierTotp(Request $request): RedirectResponse
    {
        $user = $this->utilisateurEnAttente($request);

        if (! $user) {
            return redirect()->route(self::espace($request)->route('connexion'));
        }

        if ($user->estVerrouille()) {
            $request->session()->forget(self::espace($request)->cleSession().'.en_attente');

            return redirect()->route(self::espace($request)->route('connexion'))->withErrors(['email' => $this->messageVerrouillage($user)]);
        }

        $request->validate(['code' => ['required', 'string', 'max:20']], ['code.*' => __('admin.connexion.erreurs.code')]);
        $code = (string) $request->input('code');

        $valide = str_contains($code, '-')
            ? $this->totp->utiliserCodeSecours($user, $code)
            : $this->totp->verifier($user, $code);

        if (! $valide) {
            $this->journal->echec($request, $user, (string) $user->email, 'totp', 'code_totp');

            if ($user->refresh()->estVerrouille()) {
                $request->session()->forget(self::espace($request)->cleSession().'.en_attente');

                return redirect()->route(self::espace($request)->route('connexion'))->withErrors(['email' => $this->messageVerrouillage($user)]);
            }

            return back()->withErrors(['code' => __('admin.connexion.erreurs.code')]);
        }

        return $this->terminer($request, $user, str_contains($code, '-') ? 'code_secours' : 'totp');
    }

    public function enrolement(Request $request): View|RedirectResponse
    {
        $user = $this->utilisateurEnAttente($request);

        if (! $user || $user->totpActive()) {
            return redirect()->route(self::espace($request)->route('connexion'));
        }

        $secret = $request->session()->get(self::espace($request)->cleSession().'.secret_enrolement') ?? $this->totp->genererSecret();
        $request->session()->put(self::espace($request)->cleSession().'.secret_enrolement', $secret);

        return view('admin.connexion.enrolement', [
            'espace' => self::espace($request),
            'qrCode' => $this->totp->qrCodeSvg($user, $secret),
            'secret' => trim(chunk_split($secret, 4, ' ')),
        ]);
    }

    public function confirmerEnrolement(Request $request): RedirectResponse
    {
        $user = $this->utilisateurEnAttente($request);
        $secret = $request->session()->get(self::espace($request)->cleSession().'.secret_enrolement');

        if (! $user || ! $secret || $user->totpActive()) {
            return redirect()->route(self::espace($request)->route('connexion'));
        }

        $request->validate(['code' => ['required', 'string']], ['code.*' => __('admin.connexion.erreurs.code')]);

        if (! $this->totp->verifier($user, (string) $request->input('code'), $secret)) {
            $this->journal->echec($request, $user, (string) $user->email, 'enrolement', 'code_totp');

            return back()->withErrors(['code' => __('admin.connexion.erreurs.code')]);
        }

        $codes = $this->totp->activer($user, $secret);
        $request->session()->forget(self::espace($request)->cleSession().'.secret_enrolement');
        $request->session()->put(self::espace($request)->cleSession().'.codes_secours', $codes);

        return redirect()->route(self::espace($request)->route('connexion.codes-secours'));
    }

    /**
     * Les codes de secours ne sont affichés qu'une seule fois, juste après l'enrôlement.
     */
    public function codesSecours(Request $request): View|RedirectResponse
    {
        $codes = $request->session()->get(self::espace($request)->cleSession().'.codes_secours');

        return $codes && $this->utilisateurEnAttente($request)
            ? view('admin.connexion.codes-secours', ['codes' => $codes, 'espace' => self::espace($request)])
            : redirect()->route(self::espace($request)->route('connexion'));
    }

    public function terminerEnrolement(Request $request): RedirectResponse
    {
        $user = $this->utilisateurEnAttente($request);

        if (! $user || ! $user->totpActive()) {
            return redirect()->route(self::espace($request)->route('connexion'));
        }

        $request->session()->forget(self::espace($request)->cleSession().'.codes_secours');

        return $this->terminer($request, $user, 'enrolement');
    }

    public function destroy(Request $request): RedirectResponse
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route(self::espace($request)->route('connexion'))->with('statut', __('admin.connexion.deconnecte'));
    }

    private function terminer(Request $request, User $user, string $etape): RedirectResponse
    {
        $espace = self::espace($request);

        Auth::login($user);
        $request->session()->regenerate();
        $request->session()->forget($espace->cleSession().'.en_attente');
        $request->session()->put($espace->cleSession(), [
            'totp_ok' => true,
            'debut' => now()->timestamp,
            'activite' => now()->timestamp,
        ]);

        $this->journal->succes($request, $user, $etape);

        return redirect()->intended(route($espace->accueil()));
    }

    private function utilisateurEnAttente(Request $request): ?User
    {
        $espace = self::espace($request);
        $attente = $request->session()->get($espace->cleSession().'.en_attente');

        if (! $attente || $attente['expire'] < now()->timestamp) {
            return null;
        }

        $user = User::find($attente['id']);

        return $user && $espace->autorise($user) && $user->actif ? $user : null;
    }

    private function totpFacultatif(): bool
    {
        return app()->environment('local') && config('raaga.backoffice.totp_facultatif_en_local');
    }

    private function messageVerrouillage(User $user): string
    {
        return __('admin.connexion.erreurs.verrouille', [
            'minutes' => max(1, (int) ceil(now()->diffInSeconds($user->verrouille_jusqua, absolute: true) / 60)),
        ]);
    }
}
