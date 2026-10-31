<?php

namespace Tests\Support;

use App\Services\Cartes\NavigateurChrome;
use RuntimeException;

/**
 * Doublure rapide de Chrome : le gabarit HTML est reçu comme par le vrai navigateur, puis une image unie de la taille
 * demandée (une couleur par face) ou un PDF minimal est rendu. Les tests de rendu réel appellent exigerChrome().
 * $panne : échec du démarrage (essais et journalisation).
 */
class ChromeFactice extends NavigateurChrome
{
    public int $rendus = 0;

    public bool $panne = false;

    public function essai(): void
    {
        $this->verifier();
    }

    public function captures(string $html, array $selecteurs, int $largeur, int $hauteur): array
    {
        $this->verifier();
        $images = [];
        foreach (array_values($selecteurs) as $i => $selecteur) {
            $images[$selecteur] = $this->png($largeur, $hauteur, $i);
        }

        return $images;
    }

    public function pdf(string $html): string
    {
        $this->verifier();

        return "%PDF-1.4\n1 0 obj << /Type /Catalog /Pages 2 0 R >> endobj\n2 0 obj << /Type /Pages /Kids [] /Count 0 >> endobj\n"
            ."trailer << /Root 1 0 R >>\n%%EOF\n";
    }

    public function rasteriserSvg(string $svg, int $largeur): string
    {
        $this->verifier();

        return $this->png($largeur, $largeur, 0);
    }

    private function verifier(): void
    {
        if ($this->panne) {
            throw new RuntimeException('Rendu de la carte impossible : Chrome ne démarre pas (RAAGA_CHROME_BINAIRE). Devtools could not start.');
        }
        $this->rendus++;
    }

    private function png(int $largeur, int $hauteur, int $rang): string
    {
        $image = imagecreatetruecolor($largeur, $hauteur);
        imagefill($image, 0, 0, imagecolorallocate($image, 40 * $rang, 100, 60));
        ob_start();
        imagepng($image);

        return (string) ob_get_clean();
    }
}
