<?php

namespace Tests\Support;

use App\Services\Cartes\NavigateurChrome;
use App\Services\Plateforme\ParametresPlateforme;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\ExecutableFinder;

/**
 * Réglages de la carte d'identification au complet (armoiries et symbole déposés ; organisme émetteur : dénomination
 * sociale des paramètres par défaut), sur un disque privé simulé. Rendu réel par Chrome : test ignoré sans Chrome.
 */
trait ReglagesCarteComplets
{
    protected function exigerChrome(): void
    {
        $binaire = (string) config('raaga.cartes.chrome');
        if (! is_file($binaire) && (new ExecutableFinder)->find($binaire) === null) {
            $this->markTestSkipped('Chrome introuvable (RAAGA_CHROME_BINAIRE) : rendu des cartes non testé.');
        }
        $this->app->instance(NavigateurChrome::class, new NavigateurChrome);
    }

    /**
     * @param  array<string, mixed>  $carte  surcharges de la section « carte »
     * @param  bool  $filigranes  déposer aussi les quatre filigranes (images en couleur sur fond blanc)
     * @param  string  $logo  logo de l'onglet 1 : « carre » (symbole) ou « horizontal »
     * @return array<string, string> chemins déposés
     */
    protected function completerReglagesCarte(array $carte = [], bool $filigranes = false, string $logo = 'carre'): array
    {
        Storage::fake('local');
        $disque = Storage::disk('local');
        $chemins = [
            'armoiries' => 'plateforme/armoiries-test.png',
            'logoHorizontal' => 'plateforme/logo-horizontal-test.png',
        ];
        $disque->put($chemins['armoiries'], (string) file_get_contents(public_path('armoiries.png')));
        $disque->put($chemins['logoHorizontal'], (string) file_get_contents(public_path($logo === 'horizontal' ? 'images/logo/raaga-logo-horizontal-email.png' : 'images/logo/icone-192.png')));
        if ($filigranes) {
            foreach (['filigranePrincipalRecto' => [0, 107, 60], 'filigraneSecondaireRecto' => [150, 30, 30],
                'filigranePrincipalVerso' => [40, 60, 120], 'filigraneSecondaireVerso' => [120, 80, 20]] as $champ => $rgb) {
                $chemins[$champ] = 'plateforme/'.$champ.'-test.png';
                $disque->put($chemins[$champ], $this->pngCouleur(1000, 800, $rgb));
            }
        }

        $parametres = app(ParametresPlateforme::class);
        $parametres->enregistrer('identite', [...$parametres->section('identite'), 'armoiries' => $chemins['armoiries'], 'logoHorizontal' => $chemins['logoHorizontal']], null);
        // Organisme émetteur imprimé sur la carte.
        $parametres->enregistrer('mentions', [...$parametres->section('mentions'), 'denominationSociale' => 'Raaga SA'], null);
        $parametres->enregistrer('carte', [
            ...$parametres->section('carte'),
            ...array_intersect_key($chemins, array_flip(['filigranePrincipalRecto', 'filigraneSecondaireRecto', 'filigranePrincipalVerso', 'filigraneSecondaireVerso'])),
            ...$carte,
        ], null);

        return $chemins;
    }

    /** Réglage requis retiré : la dénomination sociale (organisme émetteur imprimé sur la carte). */
    protected function retirerDenomination(): void
    {
        $parametres = app(ParametresPlateforme::class);
        $parametres->enregistrer('mentions', [...$parametres->section('mentions'), 'denominationSociale' => null], null);
    }

    /** PNG en couleur sur fond blanc opaque (image de filigrane telle que déposée), ellipse de la couleur donnée. */
    protected function pngCouleur(int $largeur, int $hauteur, array $rgb): string
    {
        $image = imagecreatetruecolor($largeur, $hauteur);
        imagefill($image, 0, 0, imagecolorallocate($image, 255, 255, 255));
        imagefilledellipse($image, intdiv($largeur, 2), intdiv($hauteur, 2), intdiv($largeur * 3, 4), intdiv($hauteur * 3, 4), imagecolorallocate($image, ...$rgb));
        ob_start();
        imagepng($image);

        return (string) ob_get_clean();
    }

    /** PNG à fond transparent avec une ellipse de la couleur donnée. */
    protected function pngTest(int $largeur, int $hauteur, array $rgb): string
    {
        $image = imagecreatetruecolor($largeur, $hauteur);
        imagesavealpha($image, true);
        imagealphablending($image, false);
        imagefill($image, 0, 0, imagecolorallocatealpha($image, 0, 0, 0, 127));
        imagefilledellipse($image, intdiv($largeur, 2), intdiv($hauteur, 2), intdiv($largeur, 2), intdiv($hauteur, 2), imagecolorallocatealpha($image, ...[...$rgb, 0]));
        ob_start();
        imagepng($image);

        return (string) ob_get_clean();
    }
}
