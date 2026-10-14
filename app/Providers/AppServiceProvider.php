<?php

namespace App\Providers;

use App\Contracts\PaymentProvider;
use App\Contracts\SmsProvider;
use App\Enums\Droit;
use App\Models\User;
use App\Services\Paiement\SimulatedProvider;
use App\Services\Plateforme\ParametresPlateforme;
use App\Services\Sms\LogSmsProvider;
use App\Support\VerrouEnvironnement;
use Database\Seeders\RoleSeeder;
use Illuminate\Console\Events\CommandFinished;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use RuntimeException;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // SMS : pilote « log » (message écrit dans les journaux) tant qu'aucun opérateur n'est branché.
        // Brancher un opérateur = ajouter son pilote ici ; le reste du code passe par App\Services\Sms\EnvoiSms.
        $this->app->bind(SmsProvider::class, fn () => match (config('raaga.sms.pilote') ?? 'log') {
            'log' => new LogSmsProvider,
            default => throw new RuntimeException('Pilote SMS inconnu : '.config('raaga.sms.pilote')),
        });

        // Paiement : seul le simulateur de recette existe pour l'instant (refusé en production).
        $this->app->bind(PaymentProvider::class, fn () => match (config('raaga.paiement.fournisseur') ?? 'simule') {
            'simule' => new SimulatedProvider,
            default => throw new RuntimeException('Fournisseur de paiement inconnu : '.config('raaga.paiement.fournisseur')),
        });

        // Un seul service par requête : il porte le cache et les valeurs d'aperçu.
        $this->app->singleton(ParametresPlateforme::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Derrière un proxy qui termine le HTTPS (APP_URL en https), la requête arrive en HTTP côté
        // Laravel : sans ça, asset()/url() génèrent des liens http:// bloqués en contenu mixte.
        if (str_starts_with((string) config('app.url'), 'https://')) {
            URL::forceScheme('https');
        }

        // Données de démonstration et paiement simulé interdits en production : démarrage refusé.
        VerrouEnvironnement::verifier((string) $this->app->environment(), (bool) config('raaga.demo'), config('raaga.paiement.fournisseur'));

        // Après chaque « php artisan migrate » réussi (même sans nouvelle migration) : table des rôles alignée
        // sur l'enum (RoleSeeder, sans effet de bord).
        Event::listen(CommandFinished::class, function (CommandFinished $evenement) {
            if ($evenement->exitCode === 0 && in_array($evenement->command, ['migrate', 'migrate:fresh', 'migrate:refresh'], true)) {
                $this->app->make(RoleSeeder::class)->run();
                $evenement->output->writeln('  Rôles synchronisés (RoleSeeder).');
            }
        });

        // Une Gate par droit du back-office, d'après la matrice App\Enums\Droit::pour().
        // Un compte désactivé n'a plus aucun droit.
        foreach (Droit::cases() as $droit) {
            Gate::define($droit->value, fn (User $user) => $user->actif
                && in_array($droit, Droit::pour($user->codeRole()), true));
        }
    }
}
