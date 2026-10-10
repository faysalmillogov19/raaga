<?php

namespace App\Support;

use App\Services\Plateforme\ParametresPlateforme;
use Illuminate\Support\Facades\Storage;

/**
 * Images de la plateforme (armoiries, logos) : fichier déposé dans les paramètres de la plateforme,
 * sinon le fichier fourni dans public/. Adresse versionnée par le fichier : un changement d'image
 * change l'adresse, si bien qu'aucun navigateur ne garde l'ancienne en cache.
 */
final class ImagesPlateforme
{
    /**
     * Fichiers fournis (valeur initiale). Le logo n'existe pour l'instant qu'en PNG : version 2x
     * (et 1x pour le srcset). TODO: remplacer par les SVG vectorisés dès qu'ils sont fournis.
     * Pas de version blanche fournie : null (le pied de page pose alors le logo sur un fond blanc).
     *
     * @var array<string, array{0: ?string, 1: ?string}> champ => [fichier 2x (ou SVG), fichier 1x]
     */
    public const DEFAUTS = [
        'armoiries' => ['armoiries.svg', null],
        'logoHorizontal' => ['images/logo/raaga-logo-horizontal-96.png', 'images/logo/raaga-logo-horizontal-48.png'],
        'logoHorizontalBlanc' => [null, null],
        'logoSymbole' => ['images/logo/raaga-symbole-64.png', 'images/logo/raaga-symbole-32.png'],
        'logoSymboleSimplifie' => ['images/logo/raaga-symbole-64.png', 'images/logo/raaga-symbole-32.png'],
    ];

    public static function existe(string $champ): bool
    {
        return array_key_exists($champ, self::DEFAUTS);
    }

    /** Fichier déposé dans les paramètres (chemin absolu), ou null. */
    private static function depose(string $champ): ?string
    {
        $chemin = app(ParametresPlateforme::class)->get('identite.'.$champ);
        $disque = Storage::disk('local');

        return $chemin && $disque->exists($chemin) ? $disque->path($chemin) : null;
    }

    /** Chemin absolu de l'image à servir, ou null si aucune (logo blanc non fourni). */
    public static function chemin(string $champ): ?string
    {
        return self::depose($champ) ?? (self::DEFAUTS[$champ][0] ? public_path(self::DEFAUTS[$champ][0]) : null);
    }

    public static function disponible(string $champ): bool
    {
        return self::chemin($champ) !== null;
    }

    public static function type(string $champ): string
    {
        return str_ends_with((string) self::chemin($champ), '.png') ? 'image/png' : 'image/svg+xml';
    }

    public static function url(string $champ): ?string
    {
        $chemin = self::chemin($champ);
        if ($chemin === null) {
            return null;
        }
        $version = substr(sha1($chemin.'|'.@filemtime($chemin)), 0, 10);

        return $champ === 'armoiries'
            ? route('armoiries', ['v' => $version])
            : route('plateforme.image', ['champ' => $champ, 'v' => $version]);
    }

    /**
     * srcset 1x/2x pour les PNG fournis par défaut (un fichier déposé est servi tel quel).
     */
    public static function srcset(string $champ): ?string
    {
        $unX = self::DEFAUTS[$champ][1] ?? null;

        return self::depose($champ) === null && $unX ? asset($unX).' 1x, '.self::url($champ).' 2x' : null;
    }

    /** Pour les PDF : image intégrée au document. */
    public static function dataUri(string $champ): ?string
    {
        $chemin = self::chemin($champ);

        return $chemin ? 'data:'.self::type($champ).';base64,'.base64_encode((string) file_get_contents($chemin)) : null;
    }
}
