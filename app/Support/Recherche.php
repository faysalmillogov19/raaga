<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder;

/**
 * Recherche « contient » insensible à la casse et aux accents, pour toutes les recherches en LIKE du projet :
 * « ouedraogo » trouve « OUÉDRAOGO ». PostgreSQL (production) : unaccent(lower(…)) des deux côtés (extension unaccent) ;
 * MySQL : la collation ignore déjà casse et accents. Les caractères % et _ saisis sont cherchés tels quels.
 * Raccourcis sur les requêtes : whereContient($colonne, $terme) et orWhereContient($colonne, $terme).
 */
final class Recherche
{
    /** « %terme% », caractères spéciaux du LIKE échappés. */
    public static function motif(string $terme): string
    {
        return '%'.addcslashes($terme, '%_\\').'%';
    }

    public static function contient(Builder|EloquentBuilder $requete, string $colonne, string $terme, string $boolean = 'and'): Builder|EloquentBuilder
    {
        $base = $requete instanceof EloquentBuilder ? $requete->getQuery() : $requete;
        if ($base->getConnection()->getDriverName() === 'pgsql') {
            $colonneSql = $base->getGrammar()->wrap($colonne);

            return $requete->whereRaw("unaccent(lower({$colonneSql}::text)) like unaccent(lower(?))", [self::motif($terme)], $boolean);
        }

        return $requete->where($colonne, 'like', self::motif($terme), $boolean);
    }

    /** Déclare whereContient et orWhereContient sur les requêtes (constructeur SQL et Eloquent). */
    public static function enregistrer(): void
    {
        Builder::macro('whereContient', fn (string $colonne, string $terme) => Recherche::contient($this, $colonne, $terme));
        Builder::macro('orWhereContient', fn (string $colonne, string $terme) => Recherche::contient($this, $colonne, $terme, 'or'));
    }
}
