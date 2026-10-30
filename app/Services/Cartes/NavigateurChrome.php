<?php

namespace App\Services\Cartes;

use HeadlessChromium\Browser;
use HeadlessChromium\BrowserFactory;
use HeadlessChromium\Clip;
use HeadlessChromium\Communication\Message;
use HeadlessChromium\Page;
use RuntimeException;
use Throwable;

/**
 * Rendu par Chrome sans interface (chrome-php) : le gabarit HTML de la carte, autonome (styles, polices et images
 * intégrés, aucune ressource distante), devient un PNG par face ou un PDF d'une page par face. Une session Chrome par
 * rendu, toujours fermée. Binaire : config raaga.cartes.chrome (RAAGA_CHROME_BINAIRE).
 */
class NavigateurChrome
{
    /**
     * Capture des éléments désignés (sélecteurs CSS), à la taille exacte demandée en pixels, fond transparent hors de
     * l'élément (coins arrondis). Le gabarit est déjà mis à l'échelle (1 px CSS = 1 px de l'image).
     *
     * @param  list<string>  $selecteurs
     * @return array<string, string> sélecteur => PNG
     */
    public function captures(string $html, array $selecteurs, int $largeur, int $hauteur): array
    {
        return $this->avecPage($html, $largeur, function (Page $page) use ($selecteurs, $largeur, $hauteur) {
            $page->getSession()->sendMessageSync(new Message('Emulation.setDefaultBackgroundColorOverride', ['color' => ['r' => 0, 'g' => 0, 'b' => 0, 'a' => 0]]));
            $images = [];
            foreach ($selecteurs as $selecteur) {
                $haut = $page->evaluate('(() => { const e = document.querySelector('.json_encode($selecteur).'); return e ? e.getBoundingClientRect().top + window.scrollY : null; })()')->getReturnValue();
                if ($haut === null) {
                    throw new RuntimeException("Rendu de la carte : élément {$selecteur} introuvable.");
                }
                $clip = new Clip(0, (float) round($haut), $largeur, $hauteur, 1);
                $images[$selecteur] = base64_decode($page->screenshot(['format' => 'png', 'clip' => $clip, 'captureBeyondViewport' => true])->getBase64());
            }

            return $images;
        });
    }

    /** PDF : une page par face, format donné par la règle @page du gabarit (85,6 × 54 mm). */
    public function pdf(string $html): string
    {
        return $this->avecPage($html, 1024, fn (Page $page) => base64_decode($page->pdf([
            'printBackground' => true,
            // Format de la règle @page du gabarit (85,6 × 54 mm) ; Chrome l'arrondit à son unité interne (85,68 × 54,02 mm).
            'preferCSSPageSize' => true,
            'marginTop' => 0, 'marginBottom' => 0, 'marginLeft' => 0, 'marginRight' => 0,
        ])->getBase64()));
    }

    /** SVG rendu en PNG à la largeur donnée (armoiries déposées en SVG, pour le filigrane). */
    public function rasteriserSvg(string $svg, int $largeur): string
    {
        $html = '<!DOCTYPE html><html><head><meta charset="utf-8"><style>html,body{margin:0;background:transparent}img{display:block;width:'.$largeur.'px;height:auto}</style></head>'
            .'<body><img id="i" alt="" src="data:image/svg+xml;base64,'.base64_encode($svg).'"></body></html>';

        return $this->avecPage($html, $largeur, function (Page $page) use ($largeur) {
            $page->getSession()->sendMessageSync(new Message('Emulation.setDefaultBackgroundColorOverride', ['color' => ['r' => 0, 'g' => 0, 'b' => 0, 'a' => 0]]));
            $hauteur = (int) ceil((float) $page->evaluate('document.getElementById("i").getBoundingClientRect().height')->getReturnValue());

            return base64_decode($page->screenshot(['format' => 'png', 'clip' => new Clip(0, 0, $largeur, max(1, $hauteur), 1)])->getBase64());
        });
    }

    /**
     * @template T
     *
     * @param  callable(Page): T  $action
     * @return T
     */
    private function avecPage(string $html, int $largeur, callable $action): mixed
    {
        $navigateur = $this->ouvrir($largeur);
        try {
            $page = $navigateur->createPage();
            $page->setHtml($html, (int) config('raaga.cartes.chrome_delai', 30) * 1000);
            // Polices intégrées : rendu seulement une fois chargées.
            $page->evaluate('document.fonts.ready.then(() => true)')->getReturnValue();

            return $action($page);
        } finally {
            $navigateur->close();
        }
    }

    private function ouvrir(int $largeur): Browser
    {
        try {
            return (new BrowserFactory((string) config('raaga.cartes.chrome')))->createBrowser([
                'headless' => true,
                'noSandbox' => true,
                'windowSize' => [$largeur, 1400],
                'startupTimeout' => (int) config('raaga.cartes.chrome_delai', 30),
                'customFlags' => ['--disable-gpu', '--hide-scrollbars', '--force-color-profile=srgb', '--font-render-hinting=none', '--disable-lcd-text'],
                'enableImages' => true,
            ]);
        } catch (Throwable $e) {
            throw new RuntimeException('Rendu de la carte impossible : Chrome ne démarre pas (RAAGA_CHROME_BINAIRE). '.$e->getMessage(), 0, $e);
        }
    }
}
