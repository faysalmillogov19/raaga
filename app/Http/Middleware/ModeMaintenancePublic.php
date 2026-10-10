<?php

namespace App\Http\Middleware;

use App\Services\Plateforme\ParametresPlateforme;
use Carbon\Carbon;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Mode maintenance des paramètres de la plateforme : le site public affiche la page de
 * maintenance (503), le back-office (/admin) reste accessible.
 */
class ModeMaintenancePublic
{
    public function __construct(private readonly ParametresPlateforme $parametres) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->parametres->get('messages.modeMaintenance.actif') || $request->is('admin', 'admin/*', 'armoiries', 'plateforme/images/*', 'site.webmanifest', 'up')) {
            return $next($request);
        }

        $finPrevue = $this->parametres->get('messages.modeMaintenance.finPrevue');

        return response()->view('public.maintenance', [
            'message' => $this->parametres->get('messages.modeMaintenance.message'),
            'finPrevue' => $finPrevue ? Carbon::parse($finPrevue) : null,
        ], 503, $finPrevue ? ['Retry-After' => max(60, now()->diffInSeconds(Carbon::parse($finPrevue), false))] : []);
    }
}
