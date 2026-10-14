<?php

namespace App\Support;

use App\Services\Plateforme\ParametresPlateforme;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Image de la bannière d'accueil : photo déposée par l'ADMIN (Paramètres > Site public), déclinée en WebP à 640,
 * 1280 et 1920 px de large ; sinon l'illustration fournie dans public/images/accueil. Adresses versionnées.
 */
final class BanniereAccueil
{
    public const LARGEURS = [640, 1280, 1920];

    public const DISQUE = 'local';

    /** Préfixe des fichiers déposés (plateforme/banniere-<uuid>), ou null. */
    private static function depose(): ?string
    {
        try {
            $base = app(ParametresPlateforme::class)->get('site_public.banniereAccueil');
        } catch (QueryException) {
            return null;
        }

        return $base && Storage::disk(self::DISQUE)->exists($base.'-'.self::LARGEURS[0].'.webp') ? $base : null;
    }

    /** Chemin absolu du fichier WebP pour une largeur, ou null si la largeur n'existe pas. */
    public static function chemin(int $largeur): ?string
    {
        if (! in_array($largeur, self::LARGEURS, true)) {
            return null;
        }
        $base = self::depose();

        return $base ? Storage::disk(self::DISQUE)->path($base.'-'.$largeur.'.webp') : public_path('images/accueil/banniere-'.$largeur.'.webp');
    }

    public static function url(int $largeur): string
    {
        $chemin = (string) self::chemin($largeur);
        $version = substr(sha1($chemin.'|'.@filemtime($chemin)), 0, 10);

        return route('banniere', ['largeur' => $largeur, 'v' => $version]);
    }

    public static function srcset(): string
    {
        return collect(self::LARGEURS)->map(fn (int $l) => self::url($l).' '.$l.'w')->implode(', ');
    }

    /**
     * Photo déposée : déclinée en WebP (640, 1280, 1920 px de large, jamais agrandie), hors du dossier public.
     * Rend le préfixe enregistré dans les paramètres.
     */
    public static function deposer(UploadedFile $fichier): string
    {
        $source = imagecreatefromstring((string) file_get_contents($fichier->getRealPath()));
        abort_if($source === false, 422);
        $base = 'plateforme/banniere-'.Str::uuid();
        $disque = Storage::disk(self::DISQUE);
        $disque->makeDirectory('plateforme');
        foreach (self::LARGEURS as $largeur) {
            $l = min($largeur, imagesx($source));
            $copie = imagescale($source, $l, (int) round($l * imagesy($source) / imagesx($source)), IMG_BICUBIC);
            imagewebp($copie, $disque->path($base.'-'.$largeur.'.webp'), 70);
            imagedestroy($copie);
        }
        imagedestroy($source);

        return $base;
    }
}
