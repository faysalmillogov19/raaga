<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Builder;

/**
 * Comptage de lignes verrouillées pour la durée de la transaction : PostgreSQL refuse « FOR UPDATE » avec une
 * fonction d'agrégat (count, sum, max…). Les lignes sont donc verrouillées par un SELECT de leurs identifiants, puis
 * comptées : même garantie (aucune action simultanée ne contourne la règle), sur PostgreSQL comme sur MySQL.
 * Raccourci sur les requêtes Eloquent : compterVerrouille().
 */
final class Verrou
{
    public static function compter(Builder $requete): int
    {
        return $requete->lockForUpdate()->pluck($requete->getModel()->getQualifiedKeyName())->count();
    }

    public static function enregistrer(): void
    {
        Builder::macro('compterVerrouille', fn () => Verrou::compter($this));
    }
}
