<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Battement du planificateur Laravel (réunion du 05/11/2026) : une tâche planifiée chaque minute enregistre l'heure de
 * son passage ; raaga:verifier-production avertit si aucun passage récent n'existe, c'est-à-dire si la tâche cron
 * « schedule:run » manque sur le serveur.
 */
final class Planificateur
{
    public const CLE = 'raaga.planificateur.battement';

    /** Au-delà, le planificateur est considéré comme arrêté. */
    public const DELAI_MINUTES = 5;

    public static function battre(): void
    {
        Cache::forever(self::CLE, now()->getTimestamp());
    }

    public static function actif(): bool
    {
        try {
            $dernier = (int) Cache::get(self::CLE, 0);
        } catch (Throwable) {
            return false;
        }

        return $dernier >= now()->subMinutes(self::DELAI_MINUTES)->getTimestamp();
    }

    /** Ligne de la tâche cron à installer, avec le chemin réel de l'application. */
    public static function ligneCron(): string
    {
        return '* * * * * cd '.base_path().' && php artisan schedule:run >> /dev/null 2>&1';
    }
}
