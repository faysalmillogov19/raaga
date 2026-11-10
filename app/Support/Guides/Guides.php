<?php

namespace App\Support\Guides;

use App\Services\Boutiques\Formalisation;
use Illuminate\Support\Facades\File;

/**
 * Rubrique « Guides » (10/11/2026) : parcours illustrés pour les citoyens, décrits dans resources/guides/*.php,
 * captures dans public/guides/{guide}/ et pages statiques public/guides/index.html, public/guides/{guide}/index.html,
 * produites par raaga:guides sur le poste de développement. Données fictives autorisées dans cette rubrique seulement.
 */
final class Guides
{
    /** Guides publiés, dans l'ordre du sommaire. */
    public const ORDRE = ['boutique'];

    /** @return list<array<string, mixed>> */
    public static function tous(): array
    {
        return array_map(fn (string $slug) => self::trouver($slug), self::ORDRE);
    }

    /** @return array<string, mixed>|null */
    public static function trouver(string $slug): ?array
    {
        $fichier = resource_path('guides/'.$slug.'.php');
        if (! in_array($slug, self::ORDRE, true) || ! is_file($fichier)) {
            return null;
        }

        return self::remplacer(require $fichier);
    }

    public static function dossier(string $slug = ''): string
    {
        return public_path('guides'.($slug !== '' ? '/'.$slug : ''));
    }

    /** Adresse publique d'une capture (fichier statique). */
    public static function image(string $slug, string $fichier): string
    {
        return '/guides/'.$slug.'/'.$fichier.'.jpg';
    }

    /** Durées tirées des réglages : « :mois » (délai de formalisation). */
    private static function remplacer(mixed $valeur): mixed
    {
        if (is_array($valeur)) {
            return array_map(fn ($v) => self::remplacer($v), $valeur);
        }

        return is_string($valeur) ? str_replace(':mois', (string) Formalisation::delaiMois(), $valeur) : $valeur;
    }

    /** Captures attendues d'un guide (noms de fichiers). @return list<string> */
    public static function captures(array $guide): array
    {
        return array_values(array_merge(...array_map(fn (array $e) => array_column($e['captures'], 'fichier'), $guide['etapes'])));
    }

    /** Captures manquantes dans public/guides/{guide}/. @return list<string> */
    public static function capturesManquantes(array $guide): array
    {
        return array_values(array_filter(self::captures($guide), fn (string $f) => ! File::exists(self::dossier($guide['slug']).'/'.$f.'.jpg')));
    }
}
