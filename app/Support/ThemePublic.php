<?php

namespace App\Support;

/**
 * Thème du site public et de l'espace citoyen (config raaga.theme_public) : « nuit » par défaut, « clair » pour le
 * rendu historique. Le thème « nuit » pose la classe .theme-nuit sur le body ; ses styles sont tous sous cette classe
 * (public/css/raaga.css), le thème « clair » n'en charge aucun.
 */
final class ThemePublic
{
    public const NUIT = 'nuit';

    public const CLAIR = 'clair';

    public static function courant(): string
    {
        return config('raaga.theme_public') === self::CLAIR ? self::CLAIR : self::NUIT;
    }

    public static function nuit(): bool
    {
        return self::courant() === self::NUIT;
    }
}
