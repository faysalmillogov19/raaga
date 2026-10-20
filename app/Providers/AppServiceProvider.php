<?php

namespace App\Providers;

use App\Contracts\PaymentProvider;
use App\Contracts\SmsProvider;
use App\Enums\Droit;
use App\Enums\StatutReservationBoutique;
use App\Enums\TypeCarte;
use App\Models\LigneRegistre;
use App\Models\ReservationBoutique;
use App\Models\User;
use App\Services\Cartes\Cartes;
use App\Services\Paiement\SimulatedProvider;
use App\Services\Plateforme\ParametresPlateforme;
use App\Services\Sms\LogSmsProvider;
use App\Support\GardeFouBase;
use App\Support\MemoRequete;
use App\Support\Recherche;
use App\Support\TitreSucces;
use App\Support\Verrou;
use App\Support\VerrouEnvironnement;
use Database\Seeders\RoleSeeder;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Console\Events\CommandFinished;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Events\RouteMatched;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use RuntimeException;
use Symfony\Component\HttpFoundation\Request as SymfonyRequest;

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
        // Proxys de confiance : derrière Nginx, l'adresse IP retenue (limites, journal) est celle du visiteur
        // (X-Forwarded-For), jamais celle du proxy ; un en-tête venu d'ailleurs est ignoré.
        TrustProxies::at(array_map('trim', explode(',', (string) config('raaga.proxys_de_confiance'))));
        TrustProxies::withHeaders(SymfonyRequest::HEADER_X_FORWARDED_FOR | SymfonyRequest::HEADER_X_FORWARDED_HOST
            | SymfonyRequest::HEADER_X_FORWARDED_PORT | SymfonyRequest::HEADER_X_FORWARDED_PROTO);

        // Vérification des cartes par QR code, contre l'essai de jetons : agent connecté autorisé (cartes.verifier) compté
        // par compte, plus large, une seule fois par scan (sur la page du guichet, pas sur la redirection de /c/{jeton}) ;
        // sinon par adresse IP. Au-delà : 429, page claire avec le délai d'attente.
        // (Portiques : seuls les essais avec une mauvaise clé sont limités, voir VerificationCarteController.)
        RateLimiter::for('cartes-verification', fn (Request $request) => (match (true) {
            ! $request->user()?->can(Droit::CartesVerifier->value) => Limit::perMinute((int) config('raaga.cartes.tentatives_par_minute'))->by('cartes:'.$request->ip()),
            $request->routeIs('cartes.verifier') => Limit::none(),
            default => Limit::perMinute((int) config('raaga.cartes.tentatives_agents_par_minute'))->by('cartes-agent:'.$request->user()->id),
        })
            ->response(fn (Request $request, array $entetes) => response()->view('errors.429', [
                'message' => __('public.cartes.verification.trop_de_tentatives', ['secondes' => $entetes['Retry-After'] ?? 60]),
            ], 429, $entetes)));

        // Cartes d'accès : attribution ou annulation d'une boutique (COMMERÇANT), inscription au registre (ACTIONNAIRE),
        // dossier d'identité conforme ou douteux, pièce d'identité, compte désactivé (PERSONNEL).
        ReservationBoutique::saved(function (ReservationBoutique $r) {
            $avant = $r->getOriginal('statut');
            if ($r->wasChanged('statut') && in_array(StatutReservationBoutique::Attribuee, [$avant, $r->statut], true)) {
                DB::afterCommit(fn () => app(Cartes::class)->synchroniser($r->user, TypeCarte::Commercant));
            }
        });
        LigneRegistre::created(fn (LigneRegistre $l) => DB::afterCommit(fn () => app(Cartes::class)->synchroniser($l->user, TypeCarte::Actionnaire)));
        User::saved(function (User $u) {
            if ($u->wasChanged('statut_kyc') || $u->wasChanged('piece_expire_le') || $u->wasChanged('actions_gelees_le')) {
                DB::afterCommit(function () use ($u) {
                    app(Cartes::class)->synchroniser($u, TypeCarte::Commercant);
                    app(Cartes::class)->synchroniser($u, TypeCarte::Actionnaire);
                });
            }
            if ($u->wasChanged('actif') && ! $u->actif) {
                DB::afterCommit(fn () => app(Cartes::class)->synchroniser($u, TypeCarte::Personnel));
            }
        });

        // Message de succès en popup : texte traduit et titre (précisé, sinon déduit de la clé, sinon « Opération réussie »).
        RedirectResponse::macro('avecSucces', function (string $cle, array $remplacements = [], ?string $titre = null) {
            /** @var RedirectResponse $this */
            return $this->with('succes', __($cle, $remplacements))->with('succes_titre', $titre ?? TitreSucces::pour($cle));
        });

        // Derrière un proxy qui termine le HTTPS (APP_URL en https), la requête arrive en HTTP côté
        // Laravel : sans ça, asset()/url() génèrent des liens http:// bloqués en contenu mixte.
        if (str_starts_with((string) config('app.url'), 'https://')) {
            URL::forceScheme('https');
        }

        // Données de démonstration et paiement simulé interdits en production : démarrage refusé.
        VerrouEnvironnement::verifier((string) $this->app->environment(), (bool) config('raaga.demo'));

        // Base protégée : en test (ou --env=testing), seulement une base « _test » ; commandes qui effacent des données
        // (migrate:fresh, db:wipe, db:seed…) refusées en production et confirmées ailleurs.
        // En environnement de test, Laravel ne relaie pas les événements de commande : la base est vérifiée dès le
        // démarrage (tests, et toute commande lancée avec --env=testing).
        if ($this->app->runningInConsole() && $this->app->environment('testing')) {
            GardeFouBase::exigerBaseDeTest();
        }
        Event::listen(CommandStarting::class, fn (CommandStarting $e) => GardeFouBase::avantCommande((string) $e->command, $e->input, $e->output));

        // Après chaque « php artisan migrate » réussi (même sans nouvelle migration) : table des rôles alignée
        // sur l'enum (RoleSeeder, sans effet de bord).
        Event::listen(CommandFinished::class, function (CommandFinished $evenement) {
            if ($evenement->exitCode === 0 && in_array($evenement->command, ['migrate', 'migrate:fresh', 'migrate:refresh'], true)) {
                $this->app->make(RoleSeeder::class)->run();
                $evenement->output->writeln('  Rôles synchronisés (RoleSeeder).');
            }
        });

        // Recherches « contient » insensibles à la casse et aux accents : whereContient, orWhereContient.
        Recherche::enregistrer();
        // Comptage de lignes verrouillées compatible PostgreSQL (pas de FOR UPDATE avec un agrégat) : compterVerrouille().
        Verrou::enregistrer();

        // Calculs coûteux de la page (file « À valider », « À traiter ») : une seule fois par requête de lecture.
        Event::listen(RouteMatched::class, fn (RouteMatched $e) => $e->request->isMethodSafe() ? MemoRequete::demarrer() : MemoRequete::arreter());
        Event::listen(RequestHandled::class, fn () => MemoRequete::arreter());

        // Une Gate par droit du back-office, d'après la matrice App\Enums\Droit::pour().
        // Un compte désactivé n'a plus aucun droit.
        foreach (Droit::cases() as $droit) {
            Gate::define($droit->value, fn (User $user) => $user->actif
                && in_array($droit, Droit::pour($user->codeRole()), true));
        }
    }
}
