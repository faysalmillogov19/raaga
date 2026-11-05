<?php

namespace App\Support;

use BaconQrCode\Common\ErrorCorrectionLevel;
use BaconQrCode\Encoder\Encoder;

/**
 * QR code en image PNG (data URI) pour les documents PDF (dompdf n'affiche pas les SVG de façon fiable) : modules noirs
 * sur fond blanc, marge de 2 modules, correction M.
 */
final class QrPng
{
    public static function dataUri(string $texte, int $pixelsParModule = 6): string
    {
        $matrice = Encoder::encode($texte, ErrorCorrectionLevel::M())->getMatrix();
        $n = $matrice->getWidth();
        $marge = 2;
        $cote = ($n + 2 * $marge) * $pixelsParModule;
        $image = imagecreatetruecolor($cote, $cote);
        imagefill($image, 0, 0, imagecolorallocate($image, 255, 255, 255));
        $noir = imagecolorallocate($image, 0, 0, 0);
        for ($y = 0; $y < $n; $y++) {
            for ($x = 0; $x < $n; $x++) {
                if ($matrice->get($x, $y) === 1) {
                    $px = ($x + $marge) * $pixelsParModule;
                    $py = ($y + $marge) * $pixelsParModule;
                    imagefilledrectangle($image, $px, $py, $px + $pixelsParModule - 1, $py + $pixelsParModule - 1, $noir);
                }
            }
        }
        ob_start();
        imagepng($image);
        imagedestroy($image);

        return 'data:image/png;base64,'.base64_encode((string) ob_get_clean());
    }
}
