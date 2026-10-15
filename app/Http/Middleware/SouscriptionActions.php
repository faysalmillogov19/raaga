<?php

namespace App\Http\Middleware;

use App\Support\Phase;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Souscription d'actions : possible seulement si le paramètre « souscription ouverte » est confirmé ET qu'une tranche
 * est ouverte. Sinon, page « Souscription bientôt ouverte » (alerte SMS) ; un envoi de formulaire est refusé (403).
 */
class SouscriptionActions
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! Phase::souscriptionOuverte()) {
            if (! $request->isMethodSafe() || $request->expectsJson()) {
                abort(403, __('public.demarches.souscription_fermee'));
            }

            return redirect()->route(Phase::presentationActions() ? 'souscription.bientot' : 'boutiques');
        }

        return $next($request);
    }
}
