<?php

namespace App\Providers;

use App\Contracts\SmsProvider;
use App\Enums\Droit;
use App\Models\User;
use App\Services\Plateforme\ParametresPlateforme;
use App\Services\Sms\LogSmsProvider;
use App\Support\VerrouEnvironnement;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Jalon 1 : aucun SMS réel, le code s'affiche dans la console du serveur.
        $this->app->bind(SmsProvider::class, LogSmsProvider::class);

        // Un seul service par requête : il porte le cache et les valeurs d'aperçu.
        $this->app->singleton(ParametresPlateforme::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Données de démonstration interdites en production : démarrage refusé.
        VerrouEnvironnement::verifier((string) $this->app->environment(), (bool) config('raaga.demo'));

        // Une Gate par droit du back-office, d'après la matrice App\Enums\Droit::pour().
        // Un compte désactivé n'a plus aucun droit.
        foreach (Droit::cases() as $droit) {
            Gate::define($droit->value, fn (User $user) => $user->actif
                && in_array($droit, Droit::pour($user->codeRole()), true));
        }
    }
}
