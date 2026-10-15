<?php

namespace Tests\Support;

use App\Services\Medias\ConversionWebp;
use Illuminate\Support\Facades\Storage;

/**
 * Doublure rapide de la conversion WebP : l'image source est contrôlée (en-tête lu, sans décodage), puis chaque
 * largeur reçoit un WebP minimal de 1 × 1 pixel. La vraie conversion est testée une fois, de bout en bout.
 */
class ConversionWebpFactice extends ConversionWebp
{
    private static ?string $webp = null;

    public function decliner(string $contenu, string $disque, string $base, array $largeurs): void
    {
        abort_if(@getimagesizefromstring($contenu) === false, 422);
        self::$webp ??= (function () {
            $image = imagecreatetruecolor(1, 1);
            ob_start();
            imagewebp($image);
            imagedestroy($image);

            return (string) ob_get_clean();
        })();
        foreach ($largeurs as $largeur) {
            Storage::disk($disque)->put($base.'-'.$largeur.'.webp', self::$webp);
        }
    }
}
