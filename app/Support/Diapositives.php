<?php

namespace App\Support;

use App\Services\Medias\ConversionWebp;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;

/**
 * Images de l'ancien carrousel d'accueil, retiré de la page d'accueil le 20/10/2026. Ne sert plus qu'à
 * CarrouselAccueilSeeder (conservé, hors de l'enchaînement des seeders) : chaque image est déclinée en WebP à 640,
 * 1280 et 1920 px de large, hors du dossier public. Les diapositives déjà enregistrées (paramètre
 * site_public.carrousel) et leurs images restent en base et sur le disque.
 */
final class Diapositives
{
    public const LARGEURS = [640, 1280, 1920];

    public const DISQUE = 'local';

    /** Image déposée : déclinée en WebP (jamais agrandie). Rend le préfixe à enregistrer. */
    public static function deposer(UploadedFile|string $fichier): string
    {
        $base = 'plateforme/diapositive-'.Str::uuid();
        app(ConversionWebp::class)->decliner((string) file_get_contents($fichier instanceof UploadedFile ? $fichier->getRealPath() : $fichier),
            self::DISQUE, $base, self::LARGEURS);

        return $base;
    }
}
