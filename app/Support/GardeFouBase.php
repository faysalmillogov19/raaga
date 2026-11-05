<?php

namespace App\Support;

use RuntimeException;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Garde-fous contre l'effacement d'une base par erreur :
 *  - environnement de test (tests, commandes lancées avec --env=testing) : refus si la base ne se termine pas par
 *    « _test » (les bases des tests en parallèle, « raaga_test_test_3 », sont acceptées) ;
 *  - commandes qui effacent ou réécrivent des données (migrate:fresh, migrate:refresh, migrate:reset, db:wipe, db:seed)
 *    hors environnement de test : refus en production (sauf le seeder idempotent des référentiels lancé par deploy.sh),
 *    confirmation explicite ailleurs (saisie du nom de la base), refus sans confirmation possible (--no-interaction).
 */
final class GardeFouBase
{
    /** Commandes qui effacent ou réécrivent des données. */
    public const COMMANDES_DESTRUCTRICES = ['migrate:fresh', 'migrate:refresh', 'migrate:reset', 'db:wipe', 'db:seed'];

    /** Seeders admis en production : idempotents, lancés à chaque déploiement (deploy.sh). */
    public const SEEDERS_PRODUCTION = ['ReferentielsSeeder', 'Database\\Seeders\\ReferentielsSeeder'];

    /** Commandes réservées au poste de développement (APP_ENV=local) : refusées partout ailleurs, avant tout accès à la base. */
    public const COMMANDES_POSTE_LOCAL = ['raaga:inventaire'];

    /** Commandes admises sur le poste de développement et en recette (réunion du 05/11/2026), jamais en production. */
    public const COMMANDES_LOCAL_OU_RECETTE = ['raaga:simulation'];

    /** Une commande réservée au poste local peut-elle tourner dans cet environnement ? */
    public static function posteLocal(?string $environnement = null): bool
    {
        return ($environnement ?? app()->environment()) === 'local';
    }

    /** @throws RuntimeException hors du poste de développement et de la recette */
    public static function exigerLocalOuRecette(string $commande): void
    {
        if (! self::posteLocal() && ! Recette::active()) {
            throw new RuntimeException("Commande refusée : « {$commande} » ne tourne que sur un poste de développement ou en recette (APP_ENV=local ou recette). "
                .'Environnement actuel : « '.app()->environment().' ». Aucun accès à la base n’a été fait.');
        }
    }

    /**
     * Confirmation d'une opération qui efface ou réécrit la base : saisie du nom de la base. Refusée sans interaction.
     *
     * @throws RuntimeException sans confirmation, ou nom différent
     */
    public static function confirmerNomBase(InputInterface $input, OutputInterface $output, string $question, string $annulee, string $sansInteraction, ?\Closure $demander = null): void
    {
        if (! $input->isInteractive()) {
            throw new RuntimeException($sansInteraction);
        }
        // Question posée par la commande elle-même quand elle le peut (style de sortie de la commande).
        $saisie = $demander ? $demander($question) : (new SymfonyStyle($input, $output))->ask($question);
        if (trim((string) $saisie) !== self::baseCourante()) {
            throw new RuntimeException($annulee);
        }
    }

    /** @throws RuntimeException hors du poste de développement */
    public static function exigerPosteLocal(string $commande): void
    {
        if (! self::posteLocal()) {
            throw new RuntimeException("Commande refusée : « {$commande} » ne tourne que sur un poste de développement (APP_ENV=local). "
                .'Environnement actuel : « '.app()->environment().' ». Aucun accès à la base n’a été fait.');
        }
    }

    public static function estBaseDeTest(?string $nom): bool
    {
        return (bool) preg_match('/_test(_test_\d+|_\d+)?$/', (string) $nom);
    }

    public static function baseCourante(): string
    {
        return (string) config('database.connections.'.config('database.default').'.database');
    }

    /** @throws RuntimeException en environnement de test sur une base qui n'est pas une base de test */
    public static function exigerBaseDeTest(): void
    {
        $base = self::baseCourante();
        if (! self::estBaseDeTest($base)) {
            throw new RuntimeException('Base refusée pour les tests : « '.$base.' » ne se termine pas par « _test ». '
                .'Les tests et les commandes lancées avec --env=testing effaceraient ses données. '
                .'Créez .env.testing à partir de .env.testing.example (DB_DATABASE=raaga_test).');
        }
    }

    /**
     * Avant chaque commande Artisan.
     *
     * @throws RuntimeException commande refusée
     */
    public static function avantCommande(string $commande, InputInterface $input, OutputInterface $output): void
    {
        if (app()->environment('testing')) {
            self::exigerBaseDeTest();

            return;
        }
        if (! in_array($commande, self::COMMANDES_DESTRUCTRICES, true)) {
            return;
        }

        $base = self::baseCourante();
        if (app()->environment('production')) {
            $seeder = ltrim((string) $input->getParameterOption('--class', ''), '\\');
            if ($commande === 'db:seed' && in_array($seeder, self::SEEDERS_PRODUCTION, true)) {
                return;
            }
            throw new RuntimeException("Commande refusée en production : « {$commande} » effacerait ou réécrirait les données de la base « {$base} ».");
        }

        self::confirmerNomBase($input, $output,
            "« {$commande} » va effacer ou réécrire les données de la base « {$base} » (".app()->environment().'). Pour confirmer, tapez le nom de la base',
            "Commande annulée : le nom saisi ne correspond pas à la base « {$base} ».",
            "Commande refusée : « {$commande} » sur la base « {$base} » demande une confirmation. Relancez-la sans --no-interaction.");
    }
}
