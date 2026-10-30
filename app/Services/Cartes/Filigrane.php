<?php

namespace App\Services\Cartes;

use GdImage;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Filigranes de la carte : l'image en couleur déposée par le SUPER_ADMIN, convertie par GD en monochrome or (#B8902F).
 * La transparence vient de la luminosité : le blanc devient transparent, les parties sombres sont les plus marquées.
 * L'opacité (principal 14 %, secondaire 11 %) est inscrite dans l'image : le gabarit n'applique aucun filtre CSS.
 * Mise en cache par fichier source et par opacité (le nom porte l'empreinte du contenu) ; le cache se régénère.
 */
class Filigrane
{
    public const OR = [184, 144, 47];

    /** Plus grand côté du filigrane en cache (le principal recto fait environ 30 mm, soit 354 px à 300 dpi). */
    private const COTE = 900;

    private const DOSSIER = 'cartes/filigranes';

    /** Chemin absolu du filigrane en cache, généré au premier appel pour ce fichier source et cette opacité. */
    public function chemin(string $source, float $opacite): string
    {
        $disque = Storage::disk('local');
        $relatif = self::DOSSIER.'/filigrane-'.sha1_file($source).'-'.(int) round($opacite * 100).'.png';
        if (! $disque->exists($relatif)) {
            $disque->put($relatif, $this->png($source, $opacite));
        }

        return $disque->path($relatif);
    }

    /** Conversion seule (aperçu d'une image pas encore enregistrée) : PNG du filigrane. */
    public function png(string $source, float $opacite): string
    {
        $image = @imagecreatefromstring((string) file_get_contents($source));
        if (! $image instanceof GdImage) {
            throw new RuntimeException('Filigrane : image illisible.');
        }
        ob_start();
        imagepng($this->convertir($image, $opacite));

        return (string) ob_get_clean();
    }

    /** Monochrome or : opacité de chaque pixel = opacité du rang × alpha source × densité (1 − luminosité, contraste étiré). */
    private function convertir(GdImage $source, float $opacite): GdImage
    {
        [$l, $h] = [imagesx($source), imagesy($source)];
        $echelle = min(1, self::COTE / max($l, $h));
        [$largeur, $hauteur] = [max(1, (int) round($l * $echelle)), max(1, (int) round($h * $echelle))];

        $reduite = imagecreatetruecolor($largeur, $hauteur);
        imagealphablending($reduite, false);
        imagesavealpha($reduite, true);
        imagefill($reduite, 0, 0, imagecolorallocatealpha($reduite, 255, 255, 255, 127));
        imagecopyresampled($reduite, $source, 0, 0, 0, 0, $largeur, $hauteur, $l, $h);

        $sortie = imagecreatetruecolor($largeur, $hauteur);
        imagealphablending($sortie, false);
        imagesavealpha($sortie, true);
        [$r, $v, $b] = self::OR;
        for ($y = 0; $y < $hauteur; $y++) {
            for ($x = 0; $x < $largeur; $x++) {
                $c = imagecolorat($reduite, $x, $y);
                $alphaSource = 1 - (($c >> 24) & 0x7F) / 127;
                $luminance = (0.299 * (($c >> 16) & 0xFF) + 0.587 * (($c >> 8) & 0xFF) + 0.114 * ($c & 0xFF)) / 255;
                // Blanc et presque blanc : transparent ; le reste, étiré sur toute la plage.
                $densite = max(0, min(1, (0.92 - $luminance) / 0.92));
                $a = $alphaSource * $densite * $opacite;
                imagesetpixel($sortie, $x, $y, imagecolorallocatealpha($sortie, $r, $v, $b, 127 - (int) round($a * 127)));
            }
        }

        return $sortie;
    }
}
