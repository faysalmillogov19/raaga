<?php

namespace App\Http\Middleware;

use App\Support\Phase;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Pages publiques des actions : hors périmètre de la phase 1 (boutiques et cautions uniquement),
 * elles renvoient vers la page des boutiques. Redirection temporaire : elles reviendront en phase 2.
 */
class ActionsPubliques
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! Phase::actionsPubliques()) {
            return $request->expectsJson() ? abort(404) : redirect()->route('boutiques');
        }

        return $next($request);
    }
}
