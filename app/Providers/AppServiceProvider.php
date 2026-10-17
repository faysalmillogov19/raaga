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
use App\Support\MemoRequete;
use App\Support\Recherche;
use App\Support\TitreSucces;
use App\Support\Verrou;
use App\Support\VerrouEnvironnement;
use Database\Seeders\RoleSeeder;
use Illuminate\Console\Events\CommandFinished;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Http\RedirectResponse;
use Illuminate\Routing\Events\RouteMatched;
use Illuminate\Support\Facades\DB;
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
            if ($u->wasChanged('statut_kyc') || $u->wasChanged('piece_expire_le')) {
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
