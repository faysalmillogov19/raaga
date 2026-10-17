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

        if (! $input->isInteractive()) {
            throw new RuntimeException("Commande refusée : « {$commande} » sur la base « {$base} » demande une confirmation. Relancez-la sans --no-interaction.");
        }
        $saisie = (new SymfonyStyle($input, $output))->ask("« {$commande} » va effacer ou réécrire les données de la base « {$base} » (".app()->environment().'). '
            .'Pour confirmer, tapez le nom de la base');
        if (trim((string) $saisie) !== $base) {
            throw new RuntimeException("Commande annulée : le nom saisi ne correspond pas à la base « {$base} ».");
        }
    }
}
