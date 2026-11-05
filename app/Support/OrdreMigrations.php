<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Ordre des migrations : leurs noms servent à l'ordre, pas au calendrier. Toute nouvelle migration est datée après la
 * plus récente du dossier database/migrations, même si sa date dépasse la date du jour. Une migration nommée avant la
 * plus récente passerait hors d'ordre sur une base déjà migrée : elle est signalée (raaga:migrations-ordre, avant
 * « migrate », et par un test sur le dépôt).
 */
final class OrdreMigrations
{
    public const DOSSIER = 'database/migrations';

    /** Noms des migrations du dossier (sans « .php »), dans l'ordre d'exécution. @return list<string> */
    public static function fichiers(?string $dossier = null): array
    {
        $noms = array_map(fn (string $f) => basename($f, '.php'), glob(($dossier ?? base_path(self::DOSSIER)).'/*.php') ?: []);
        sort($noms, SORT_STRING);

        return $noms;
    }

    /**
     * Migrations non encore exécutées dont le nom passe avant la plus récente déjà exécutée.
     *
     * @param  list<string>  $fichiers
     * @param  list<string>  $executees
     * @return list<string>
     */
    public static function enRetard(array $fichiers, array $executees): array
    {
        if ($executees === []) {
            return [];
        }
        $derniere = max($executees);

        return array_values(array_filter(array_diff($fichiers, $executees), fn (string $nom) => strcmp($nom, $derniere) < 0));
    }

    /**
     * Migrations ajoutées au dossier (absentes du dernier commit) nommées avant la plus récente du dernier commit.
     *
     * @param  list<string>|null  $versionnees  noms au dernier commit (null : lus avec git ; git absent : rien)
     * @return list<string>
     */
    public static function ajouteesEnRetard(?array $versionnees = null, ?array $fichiers = null): array
    {
        $versionnees ??= self::versionnees();

        return self::enRetard($fichiers ?? self::fichiers(), $versionnees ?? []);
    }

    /** Migrations exécutées sur la base courante (table migrations), ou [] si elle n'existe pas. @return list<string> */
    public static function executees(): array
    {
        try {
            return Schema::hasTable('migrations') ? DB::table('migrations')->pluck('migration')->all() : [];
        } catch (Throwable) {
            return [];
        }
    }

    /** Noms au dernier commit, ou null si git n'est pas disponible. @return list<string>|null */
    private static function versionnees(): ?array
    {
        try {
            $resultat = Process::path(base_path())->run(['git', 'ls-tree', '--name-only', 'HEAD', self::DOSSIER.'/']);
        } catch (Throwable) {
            return null;
        }
        if (! $resultat->successful()) {
            return null;
        }

        return array_values(array_filter(array_map(fn (string $l) => basename(trim($l), '.php'), explode("\n", $resultat->output())),
            fn (string $nom) => $nom !== ''));
    }
}
