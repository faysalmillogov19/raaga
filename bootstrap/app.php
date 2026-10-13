<?php

use App\Http\Middleware\ActionsPubliques;
use App\Http\Middleware\AuthentifieBackoffice;
use App\Http\Middleware\DeconnecterCompteDesactive;
use App\Http\Middleware\EnsureUserHasRole;
use App\Http\Middleware\ModeMaintenancePublic;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->web(append: [DeconnecterCompteDesactive::class, ModeMaintenancePublic::class]);

        $middleware->alias([
            'role' => EnsureUserHasRole::class,
            'backoffice' => AuthentifieBackoffice::class,
            'actions.publiques' => ActionsPubliques::class,
        ]);

        // Back-office et institutionnels → e-mail + mot de passe + TOTP ; tous les autres → téléphone + SMS.
        $middleware->redirectGuestsTo(fn (Request $request) => match (true) {
            $request->is('admin', 'admin/*') => route('admin.connexion'),
            $request->is('institutionnel', 'institutionnel/*') => route('institutionnel.connexion'),
            default => route('connexion'),
        });

        // Déjà connecté sur une page de connexion : citoyen → son espace, autres rôles → leur tableau de bord.
        $middleware->redirectUsersTo(fn (Request $request) => $request->user()?->isCitoyen()
            ? route('espace')
            : route('dashboard'));
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
