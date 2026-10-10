<?php

namespace App\Http\Middleware;

use App\Enums\Espace;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Accès à un espace sécurisé (« backoffice » ou « backoffice:institutionnel ») : profil de l'espace
 * actif, connexion complète (mot de passe + TOTP), session de 8 h maximum et déconnexion après
 * 30 minutes d'inactivité.
 */
class AuthentifieBackoffice
{
    public function handle(Request $request, Closure $next, string $espace = 'backoffice'): Response
    {
        $espace = Espace::from($espace);
        $user = $request->user();

        if (! $user) {
            return redirect()->guest(route($espace->route('connexion')));
        }

        // Connecté avec un autre profil (citoyen, agent, autre espace…) : accès interdit.
        if (! $espace->autorise($user)) {
            abort(403, __('admin.erreurs.acces'));
        }

        $session = $request->session()->get($espace->cleSession(), []);
        $maintenant = now()->timestamp;

        $motif = match (true) {
            ! $user->actif => 'desactive',
            empty($session['totp_ok']) => 'double_authentification',
            $maintenant - ($session['debut'] ?? 0) > config('raaga.backoffice.session_heures') * 3600 => 'session_expiree',
            $maintenant - ($session['activite'] ?? 0) > config('raaga.backoffice.inactivite_minutes') * 60 => 'inactivite',
            default => null,
        };

        if ($motif !== null) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->guest(route($espace->route('connexion')))->with('statut', __('admin.connexion.fin_session.'.$motif));
        }

        $request->session()->put($espace->cleSession().'.activite', $maintenant);

        return $next($request);
    }
}
