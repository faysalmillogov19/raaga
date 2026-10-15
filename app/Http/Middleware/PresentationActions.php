<?php

namespace App\Http\Middleware;

use App\Support\Phase;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Pages d'information sur les actions : tant que la « présentation des actions » n'est pas visible (décision de
 * phase confirmée par le VALIDATEUR), elles renvoient vers la page des boutiques (redirection temporaire).
 */
class PresentationActions
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! Phase::presentationActions()) {
            return $request->expectsJson() ? abort(404) : redirect()->route('boutiques');
        }

        return $next($request);
    }
}
