<?php

namespace App\Services\Medias;

use Illuminate\Support\Facades\Storage;

/**
 * Déclinaison d'une image en WebP à plusieurs largeurs (jamais agrandie), sur un disque. Service à part pour pouvoir
 * être remplacé par une doublure rapide dans les tests (un seul test fait la vraie conversion de bout en bout).
 */
class ConversionWebp
{
    /**
     * @param  string  $contenu  octets de l'image source (JPEG, PNG ou WebP)
     * @param  list<int>  $largeurs
     */
    public function decliner(string $contenu, string $disque, string $base, array $largeurs): void
    {
        $source = imagecreatefromstring($contenu);
        abort_if($source === false, 422);
        $stockage = Storage::disk($disque);
        $stockage->makeDirectory(dirname($base));
        foreach ($largeurs as $largeur) {
            $l = min($largeur, imagesx($source));
            $copie = imagescale($source, $l, (int) round($l * imagesy($source) / imagesx($source)), IMG_BICUBIC);
            imagewebp($copie, $stockage->path($base.'-'.$largeur.'.webp'), 70);
            imagedestroy($copie);
        }
        imagedestroy($source);
    }
}
