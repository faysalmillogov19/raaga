<?php

namespace App\Http\Middleware;

use App\Enums\Espace;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Un compte désactivé par le SUPER_ADMIN est déconnecté à sa requête suivante, sur tout le site.
 */
class DeconnecterCompteDesactive
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && ! $user->actif) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            $espace = Espace::pour($user);

            return $espace
                ? redirect()->route($espace->route('connexion'))->with('statut', __('admin.connexion.fin_session.desactive'))
                : redirect()->route('connexion')->withErrors(['telephone' => __('public.compte.connexion.desactive')]);
        }

        return $next($request);
    }
}
