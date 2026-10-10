<?php

namespace App\Support;

use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Garde-fous de démarrage : les fonctions réservées au développement et à la recette (données de
 * démonstration, et plus tard le paiement simulé) ne peuvent jamais être actives en production.
 * L'application refuse alors de démarrer, avec un message explicite dans les journaux.
 */
final class VerrouEnvironnement
{
    /**
     * @throws RuntimeException en production si une fonction de recette est activée
     */
    public static function verifier(string $environnement, bool $demo): void
    {
        if ($environnement !== 'production' || ! $demo) {
            return;
        }

        $message = 'Démarrage refusé : RAAGA_DEMO=true est interdit avec APP_ENV=production '
            .'(des données fictives s’afficheraient sur le site). Passez RAAGA_DEMO à false.';

        Log::critical($message);

        throw new RuntimeException($message);
    }

    /** Données de démonstration actives (jamais en production : voir verifier()). */
    public static function demonstration(): bool
    {
        return (bool) config('raaga.demo') && ! app()->environment('production');
    }
}
