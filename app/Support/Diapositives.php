<?php

namespace App\Support;

use App\Services\Medias\ConversionWebp;
use App\Services\Plateforme\ParametresPlateforme;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Carrousel de la page d'accueil (Paramètres > Site public > Carrousel). Chaque image déposée est déclinée en WebP
 * à 640, 1280 et 1920 px de large, hors du dossier public ; la page d'accueil n'affiche que les diapositives actives
 * dans leur période d'affichage, dans l'ordre, 5 au plus.
 */
final class Diapositives
{
    public const LARGEURS = [640, 1280, 1920];

    public const MAX_ACTIVES = 5;

    /** Nombre maximal de diapositives enregistrées (actives ou non). */
    public const MAX_TOTAL = 10;

    public const DISQUE = 'local';

    /** Préfixe des fichiers : plateforme/diapositive-<uuid>. */
    public const FORMAT = '#^plateforme/diapositive-[0-9a-f-]{36}$#';

    /**
     * Diapositives à afficher sur l'accueil (actives, dans leur période, image présente).
     *
     * @param  list<array<string, mixed>>|null  $liste  liste fournie (aperçu), sinon celle des paramètres
     * @param  bool  $periode  false : dates d'affichage ignorées (aperçu)
     * @return Collection<int, array<string, mixed>>
     */
    public static function affichees(?array $liste = null, bool $periode = true): Collection
    {
        if ($liste === null) {
            try {
                $liste = (array) app(ParametresPlateforme::class)->get('site_public.carrousel', []);
            } catch (QueryException) {
                $liste = [];
            }
        }
        $aujourdhui = now()->toDateString();

        return collect($liste)
            ->filter(fn (array $d) => ($d['active'] ?? false) && self::existe($d['image'] ?? null)
                && (! $periode || (($d['debut'] ?? null) === null || $d['debut'] <= $aujourdhui)
                    && (($d['fin'] ?? null) === null || $d['fin'] >= $aujourdhui)))
            ->sortBy('ordre')->take(self::MAX_ACTIVES)->values();
    }

    public static function existe(?string $base): bool
    {
        return $base !== null && preg_match(self::FORMAT, $base) === 1
            && Storage::disk(self::DISQUE)->exists($base.'-'.self::LARGEURS[0].'.webp');
    }

    /** Chemin absolu du fichier WebP, ou null. */
    public static function chemin(string $base, int $largeur): ?string
    {
        return in_array($largeur, self::LARGEURS, true) && self::existe($base) ? Storage::disk(self::DISQUE)->path($base.'-'.$largeur.'.webp') : null;
    }

    public static function url(string $base, int $largeur): string
    {
        $chemin = (string) self::chemin($base, $largeur);

        return route('diapositive', ['image' => Str::after($base, 'plateforme/diapositive-'), 'largeur' => $largeur, 'v' => substr(sha1($chemin.'|'.@filemtime($chemin)), 0, 10)]);
    }

    public static function srcset(string $base): string
    {
        return collect(self::LARGEURS)->map(fn (int $l) => self::url($base, $l).' '.$l.'w')->implode(', ');
    }

    /** Image déposée : déclinée en WebP (jamais agrandie). Rend le préfixe à enregistrer. */
    public static function deposer(UploadedFile|string $fichier): string
    {
        $base = 'plateforme/diapositive-'.Str::uuid();
        app(ConversionWebp::class)->decliner((string) file_get_contents($fichier instanceof UploadedFile ? $fichier->getRealPath() : $fichier),
            self::DISQUE, $base, self::LARGEURS);

        return $base;
    }
}
