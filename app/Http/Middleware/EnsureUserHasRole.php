<?php

namespace App\Http\Middleware;

use App\Enums\Role;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserHasRole
{
    /**
     * Autorise la requête si l'utilisateur possède l'un des rôles donnés.
     * Aucun rôle n'a d'accès implicite : le superadmin et l'admin n'accèdent pas
     * aux fonctionnalités métier (séparation des tâches).
     *
     * Utilisation : ->middleware('role:validateur') ou ->middleware('role:conformite,auditeur')
     */
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();

        if (! $user) {
            return redirect()->guest(route('connexion'));
        }

        $allowed = array_map(fn (string $role) => Role::from($role), $roles);

        if (! $user->hasRole(...$allowed)) {
            abort(403, "Vous n'avez pas les droits nécessaires pour accéder à cette page.");
        }

        return $next($request);
    }
}
