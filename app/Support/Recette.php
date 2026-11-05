<?php

namespace App\Support;

/**
 * Environnement de recette (réunion du 05/11/2026) : serveur séparé de la production (APP_ENV=recette, base et stockage
 * propres) où le comité technique teste la plateforme. Bandeau « ENVIRONNEMENT DE TEST » sur toutes les pages, comptes
 * de recette par profil (mots de passe générés), paiements par le simulateur et SMS lisibles, remise à zéro par commande.
 * Jamais de bandeau en local ni en production.
 */
final class Recette
{
    public const ENVIRONNEMENT = 'recette';

    public static function active(): bool
    {
        return app()->environment(self::ENVIRONNEMENT);
    }
}
