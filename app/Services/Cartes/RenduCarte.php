<?php

namespace App\Services\Cartes;

use App\Enums\TypeCarte;
use App\Models\Carte;
use App\Models\KycDocument;
use App\Services\Kyc\VerificationIdentite;
use BaconQrCode\Common\ErrorCorrectionLevel;
use BaconQrCode\Encoder\ByteMatrix;
use BaconQrCode\Encoder\Encoder;
use Barryvdh\DomPDF\Facade\Pdf;
use GdImage;
use Illuminate\Support\Facades\Storage;

/**
 * Rendu de la carte d'accès Raaga, toujours depuis son état courant (la carte se régénère après chaque changement ;
 * le nom du fichier porte la version) : QR code (adresse de vérification seule), image PNG 300 ppp au format carte
 * bancaire (85,6 × 54 mm), PDF de même format prêt pour une imprimante classique ou à cartes. Aucune ressource distante.
 */
class RenduCarte
{
    /** Format carte bancaire ISO/CEI 7810 ID-1 à 300 ppp. */
    public const LARGEUR = 1012;

    public const HAUTEUR = 638;

    /** Couleurs de la charte Raaga. */
    private const VERT = [0, 132, 61];

    private const VERT_FONCE = [0, 92, 43];

    private const ROUGE = [206, 17, 38];

    private const JAUNE = [252, 209, 22];

    private const ENCRE = [24, 32, 28];

    private const GRIS = [90, 102, 114];

    /** Matrice du QR code : uniquement l'URL de vérification (jeton aléatoire), sans aucune donnée personnelle. */
    public function matrice(Carte $carte): ByteMatrix
    {
        return Encoder::encode($carte->urlVerification(), ErrorCorrectionLevel::M())->getMatrix();
    }

    /** QR code en SVG (affichage à l'écran, plein écran pour le scan). */
    public function qrSvg(Carte $carte, string $titre = ''): string
    {
        $m = $this->matrice($carte);
        $n = $m->getWidth();
        $chemin = '';
        for ($y = 0; $y < $n; $y++) {
            for ($x = 0; $x < $n; $x++) {
                if ($m->get($x, $y) === 1) {
                    $chemin .= 'M'.($x + 2).' '.($y + 2).'h1v1h-1z';
                }
            }
        }
        $t = $n + 4;

        return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 '.$t.' '.$t.'" shape-rendering="crispEdges" role="img" aria-label="'.e($titre).'">'
            .'<rect width="'.$t.'" height="'.$t.'" fill="#fff"/><path fill="#000" d="'.$chemin.'"/></svg>';
    }

    public function nomFichier(Carte $carte, string $extension): string
    {
        return 'carte-raaga-'.strtolower($carte->numero).'-v'.$carte->version.'.'.$extension;
    }

    /** Image PNG de la carte (300 ppp). */
    public function png(Carte $carte): string
    {
        $image = $this->dessiner($carte);
        ob_start();
        imagepng($image, null, 6);

        return (string) ob_get_clean();
    }

    /** PDF au format carte bancaire (85,6 × 54 mm, sans marge), image de la carte à 300 ppp. */
    public function pdf(Carte $carte): string
    {
        $html = '<!DOCTYPE html><html><head><meta charset="utf-8"><style>@page{margin:0}html,body{margin:0;padding:0}'
            .'img{display:block;width:85.6mm;height:54mm}</style></head><body>'
            .'<img src="data:image/png;base64,'.base64_encode($this->png($carte)).'" alt="'.e($carte->numero).'"></body></html>';

        // 85,6 × 54 mm en points (1 mm = 72 / 25,4 pt).
        return Pdf::loadHTML($html)->setPaper([0, 0, 242.65, 153.07])
            ->setOption(['isRemoteEnabled' => false, 'defaultFont' => 'DejaVu Sans', 'dpi' => 300])
            ->output();
    }

    private function dessiner(Carte $carte): GdImage
    {
        $c = $carte->contenu ?? [];
        $t = __('public.cartes.carte');
        $img = imagecreatetruecolor(self::LARGEUR, self::HAUTEUR);
        imageantialias($img, true);
        $couleur = fn (array $rgb) => imagecolorallocate($img, ...$rgb);
        $blanc = $couleur([255, 255, 255]);
        imagefilledrectangle($img, 0, 0, self::LARGEUR, self::HAUTEUR, $blanc);

        // Bandeau : couleur selon le type (vert : commerçant, vert foncé : actionnaire, encre : personnel).
        $fond = $couleur(match ($carte->type) {
            TypeCarte::Commercant => self::VERT,
            TypeCarte::Actionnaire => self::VERT_FONCE,
            TypeCarte::Personnel => self::ENCRE,
        });
        imagefilledrectangle($img, 0, 0, self::LARGEUR, 132, $fond);
        // Logo : « R » sur carré blanc arrondi, puis la marque et le type de carte.
        $this->rectangleArrondi($img, 36, 30, 108, 102, 16, $blanc);
        $this->texte($img, 'R', 50, 52, 96, $fond, true);
        $this->texte($img, 'Raaga', 30, 128, 80, $blanc, true);
        $this->texte($img, mb_strtoupper(__('public.cartes.carte.carte_acces')), 15, 130, 108, $blanc);
        $type = mb_strtoupper($carte->type->label());
        $this->texte($img, $type, 26, self::LARGEUR - 40 - $this->largeurTexte($type, 26, true), 80, $blanc, true);

        // Liseré aux couleurs nationales, en pied.
        imagefilledrectangle($img, 0, self::HAUTEUR - 14, (int) (self::LARGEUR / 2), self::HAUTEUR, $couleur(self::ROUGE));
        imagefilledrectangle($img, (int) (self::LARGEUR / 2), self::HAUTEUR - 14, self::LARGEUR, self::HAUTEUR, $couleur(self::VERT));
        imagefilledrectangle($img, (int) (self::LARGEUR / 2) - 20, self::HAUTEUR - 14, (int) (self::LARGEUR / 2) + 20, self::HAUTEUR, $couleur(self::JAUNE));

        // Photo (selfie validé) ou initiale.
        $this->photo($img, $c, 36, 164, 210, 262);

        // Informations.
        $encre = $couleur(self::ENCRE);
        $gris = $couleur(self::GRIS);
        $x = 276;
        $y = 196;
        // Personne morale : raison sociale en titre, puis le représentant légal (nom et photo).
        foreach ($this->couper((string) ($c['raison_sociale'] ?? $c['nom'] ?? ''), 30, 450, true) as $ligne) {
            $this->texte($img, $ligne, 30, $x, $y, $encre, true);
            $y += 42;
        }
        if (! empty($c['raison_sociale'])) {
            foreach ($this->couper($t['representant'].' '.($c['nom'] ?? ''), 19, 450) as $ligne) {
                $this->texte($img, $ligne, 19, $x, $y, $encre);
                $y += 30;
            }
        }
        $y += 8;
        $lignes = [];
        $emplacements = $c['emplacements'] ?? [];
        foreach (array_slice($emplacements, 0, 3) as $e) {
            $lignes[] = [$e['code'].' · '.$e['site'], true];
        }
        if (count($emplacements) > 3) {
            $lignes[] = [__('public.cartes.carte.autres_emplacements', ['n' => count($emplacements) - 3]), false];
        }
        if (! empty($c['numero_actionnaire'])) {
            $lignes[] = [$t['numero_actionnaire'].' '.$c['numero_actionnaire'], true];
        }
        if (! empty($c['fonction'])) {
            $lignes[] = [$c['fonction'], true];
        }
        foreach ($lignes as [$ligne, $gras]) {
            foreach ($this->couper($ligne, 19, 450, $gras) as $morceau) {
                $this->texte($img, $morceau, 19, $x, $y, $encre, $gras);
                $y += 30;
            }
        }
        $this->texte($img, $t['numero'].' '.$carte->numero, 19, $x, 540, $gris);
        if ($carte->valide_jusqu_au) {
            $this->texte($img, $t['valide_jusqu_au'].' '.$carte->valide_jusqu_au->format('d/m/Y'), 19, $x, 572, $encre, true);
        }

        // QR code (adresse de vérification seule).
        $this->qr($img, $this->matrice($carte), 746, 330, 230);

        return $img;
    }

    private function qr(GdImage $img, ByteMatrix $m, int $x0, int $y0, int $cote): void
    {
        $n = $m->getWidth();
        $module = intdiv($cote, $n + 2);
        $marge = intdiv($cote - $module * $n, 2);
        $noir = imagecolorallocate($img, 0, 0, 0);
        imagefilledrectangle($img, $x0, $y0 - $cote / 2, $x0 + $cote, (int) ($y0 + $cote / 2), imagecolorallocate($img, 255, 255, 255));
        $haut = (int) ($y0 - $cote / 2);
        for ($y = 0; $y < $n; $y++) {
            for ($x = 0; $x < $n; $x++) {
                if ($m->get($x, $y) === 1) {
                    imagefilledrectangle($img, $x0 + $marge + $x * $module, $haut + $marge + $y * $module,
                        $x0 + $marge + ($x + 1) * $module - 1, $haut + $marge + ($y + 1) * $module - 1, $noir);
                }
            }
        }
    }

    /** @param array<string, mixed> $c */
    private function photo(GdImage $img, array $c, int $x, int $y, int $l, int $h): void
    {
        $source = null;
        if (! empty($c['photo'])) {
            $chemin = KycDocument::query()->whereKey($c['photo'])->value('chemin_fichier');
            $donnees = $chemin ? Storage::disk(VerificationIdentite::DISQUE)->get($chemin) : null;
            $source = $donnees ? @imagecreatefromstring($donnees) : null;
        }
        if ($source instanceof GdImage) {
            // Recadrage « cover » au format de la photo.
            [$sl, $sh] = [imagesx($source), imagesy($source)];
            $echelle = max($l / $sl, $h / $sh);
            $cl = (int) round($l / $echelle);
            $ch = (int) round($h / $echelle);
            imagecopyresampled($img, $source, $x, $y, (int) (($sl - $cl) / 2), (int) (($sh - $ch) / 2), $l, $h, $cl, $ch);

            return;
        }
        imagefilledrectangle($img, $x, $y, $x + $l, $y + $h, imagecolorallocate($img, 232, 240, 235));
        $initiale = mb_strtoupper(mb_substr((string) ($c['nom'] ?? ''), 0, 1));
        $this->texte($img, $initiale, 72, $x + (int) (($l - $this->largeurTexte($initiale, 72, true)) / 2), $y + (int) ($h / 2) + 36,
            imagecolorallocate($img, ...self::GRIS), true);
    }

    private function rectangleArrondi(GdImage $img, int $x1, int $y1, int $x2, int $y2, int $r, int $couleur): void
    {
        imagefilledrectangle($img, $x1 + $r, $y1, $x2 - $r, $y2, $couleur);
        imagefilledrectangle($img, $x1, $y1 + $r, $x2, $y2 - $r, $couleur);
        foreach ([[$x1 + $r, $y1 + $r], [$x2 - $r, $y1 + $r], [$x1 + $r, $y2 - $r], [$x2 - $r, $y2 - $r]] as [$cx, $cy]) {
            imagefilledellipse($img, $cx, $cy, 2 * $r, 2 * $r, $couleur);
        }
    }

    private function police(bool $gras): string
    {
        return base_path('vendor/dompdf/dompdf/lib/fonts/DejaVuSans'.($gras ? '-Bold' : '').'.ttf');
    }

    private function texte(GdImage $img, string $texte, int $taille, int $x, int $y, int $couleur, bool $gras = false): void
    {
        if (function_exists('imagettftext') && is_file($this->police($gras))) {
            imagettftext($img, $taille * 0.75, 0, $x, $y, $couleur, $this->police($gras), $texte);

            return;
        }
        imagestring($img, 5, $x, $y - 15, $this->ascii($texte), $couleur);
    }

    private function largeurTexte(string $texte, int $taille, bool $gras = false): int
    {
        if (! function_exists('imagettfbbox') || ! is_file($this->police($gras))) {
            return strlen($texte) * 9;
        }
        $b = imagettfbbox($taille * 0.75, 0, $this->police($gras), $texte);

        return (int) abs($b[2] - $b[0]);
    }

    /** @return list<string> lignes d'au plus $largeur pixels (2 lignes au maximum, la dernière abrégée). */
    private function couper(string $texte, int $taille, int $largeur, bool $gras = false): array
    {
        $lignes = [''];
        foreach (preg_split('/\s+/u', trim($texte)) ?: [] as $mot) {
            $essai = trim(end($lignes).' '.$mot);
            if ($this->largeurTexte($essai, $taille, $gras) <= $largeur || end($lignes) === '') {
                $lignes[array_key_last($lignes)] = $essai;
            } else {
                $lignes[] = $mot;
            }
        }
        $lignes = array_slice($lignes, 0, 2);
        while ($this->largeurTexte($lignes[array_key_last($lignes)], $taille, $gras) > $largeur && mb_strlen($lignes[array_key_last($lignes)]) > 2) {
            $lignes[array_key_last($lignes)] = mb_substr($lignes[array_key_last($lignes)], 0, -2).'…';
        }

        return $lignes;
    }

    private function ascii(string $texte): string
    {
        return (string) iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $texte);
    }
}
