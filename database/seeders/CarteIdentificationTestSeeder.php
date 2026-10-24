<?php

namespace Database\Seeders;

use App\Services\Plateforme\ParametresPlateforme;
use App\Support\GardeFouBase;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Réglages de TEST de la carte d'identification, pour le poste de développement seulement (APP_ENV=local) :
 * textes marqués « TEST », signature et cachet dessinés à fond transparent. Jamais appelé par DatabaseSeeder :
 * en production les champs restent vides tant que le SUPER_ADMIN ne les a pas remplis.
 *
 * php artisan db:seed --class=CarteIdentificationTestSeeder
 */
class CarteIdentificationTestSeeder extends Seeder
{
    public function run(ParametresPlateforme $parametres): void
    {
        GardeFouBase::exigerPosteLocal('CarteIdentificationTestSeeder');

        $parametres->enregistrer('carte', [
            ...$parametres->section('carte'),
            'qualiteAutorite' => 'TEST · Qualité de l’autorité de délivrance',
            'nomAutorite' => 'TEST · Nom de l’autorité',
            'signatureAutorite' => $this->deposer('signature-autorite', $this->signature()),
            'cachetAutorite' => $this->deposer('cachet-autorite', $this->cachet()),
        ], null);
    }

    /** Même nommage que les fichiers déposés depuis l'administration : nom unique, aucun fichier écrasé. */
    private function deposer(string $prefixe, \GdImage $image): string
    {
        ob_start();
        imagepng($image);
        $chemin = 'plateforme/'.$prefixe.'-'.Str::uuid().'.png';
        Storage::disk('local')->put($chemin, (string) ob_get_clean());

        return $chemin;
    }

    private function toile(int $largeur, int $hauteur): \GdImage
    {
        $image = imagecreatetruecolor($largeur, $hauteur);
        imagesavealpha($image, true);
        imagealphablending($image, false);
        imagefill($image, 0, 0, imagecolorallocatealpha($image, 0, 0, 0, 127));
        imagealphablending($image, true);

        return $image;
    }

    private function signature(): \GdImage
    {
        $image = $this->toile(900, 300);
        $encre = imagecolorallocate($image, 20, 40, 120);
        imagesetthickness($image, 6);
        $x0 = 60;
        $y0 = 170;
        for ($x = 60; $x <= 820; $x += 20) {
            $y = (int) (170 + 60 * sin($x / 45) - ($x - 60) / 12);
            imageline($image, $x0, $y0, $x, $y, $encre);
            [$x0, $y0] = [$x, $y];
        }
        imagestring($image, 5, 380, 250, 'TEST', $encre);

        return $image;
    }

    private function cachet(): \GdImage
    {
        $image = $this->toile(700, 700);
        $encre = imagecolorallocatealpha($image, 30, 60, 160, 10);
        // imagesetthickness n'agit pas sur les ellipses : anneaux épaissis par cercles concentriques.
        foreach ([[640, 12], [470, 8]] as [$diametre, $epaisseur]) {
            for ($i = 0; $i < $epaisseur; $i++) {
                imageellipse($image, 350, 350, $diametre - $i, $diametre - $i, $encre);
            }
        }
        $grand = imagecreatetruecolor(40, 16);
        imagesavealpha($grand, true);
        imagealphablending($grand, false);
        imagefill($grand, 0, 0, imagecolorallocatealpha($grand, 0, 0, 0, 127));
        imagestring($grand, 5, 2, 0, 'TEST', imagecolorallocate($grand, 30, 60, 160));
        imagealphablending($image, true);
        imagecopyresampled($image, $grand, 190, 290, 0, 0, 320, 128, 40, 16);

        return $image;
    }
}
