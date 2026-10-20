<?php

namespace App\Support;

/**
 * Thème du site public et de l'espace citoyen (config raaga.theme_public) :
 *  - « lumiere » (défaut) : mise en page du thème « nuit » (mêmes vues, composants et classes) en couleurs claires ;
 *  - « nuit » : la même mise en page sur fonds bleu nuit ;
 *  - « clair » : rendu historique, inchangé.
 * Les deux premiers posent la classe .theme-nuit sur le body (leurs styles sont sous cette classe, public/css/raaga.css) ;
 * « lumiere » y ajoute .theme-lumiere, qui redéfinit les couleurs. Le thème « clair » n'en charge aucun.
 */
final class ThemePublic
{
    public const LUMIERE = 'lumiere';

    public const NUIT = 'nuit';

    public const CLAIR = 'clair';

    public static function courant(): string
    {
        return match (config('raaga.theme_public')) {
            self::CLAIR => self::CLAIR,
            self::NUIT => self::NUIT,
            default => self::LUMIERE,
        };
    }

    /** Mise en page du thème « nuit » (vues public/nuit, index-nuit, x-public.*) : thèmes « nuit » et « lumiere ». */
    public static function nuit(): bool
    {
        return self::courant() !== self::CLAIR;
    }

    /** Fonds sombres (thème « nuit » seul). */
    public static function sombre(): bool
    {
        return self::courant() === self::NUIT;
    }

    /** Couleurs claires sur la mise en page du thème « nuit ». */
    public static function lumiere(): bool
    {
        return self::courant() === self::LUMIERE;
    }
}
