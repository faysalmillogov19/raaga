<?php

namespace App\Services\Cartes;

use App\Enums\TypeCarte;
use App\Models\Carte;
use App\Models\KycDocument;
use App\Services\Kyc\VerificationIdentite;
use App\Support\ImagesPlateforme;
use App\Support\Plateforme\Catalogue;
use App\Support\Plateforme\ReglagesCarte;
use App\Support\Telephone;
use BaconQrCode\Common\ErrorCorrectionLevel;
use BaconQrCode\Encoder\ByteMatrix;
use BaconQrCode\Encoder\Encoder;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Rendu de la carte d'identification Raaga (maquette validée le 24/10/2026), toujours depuis l'état courant de la carte
 * (le nom du fichier porte la version) : un seul gabarit HTML (resources/views/cartes) rendu par Chrome sans interface
 * en PNG 300 dpi (1011 × 638 px par face) et en PDF d'une page par face (85,6 × 54 mm), et affiché tel quel dans
 * l'aperçu du back-office. Armoiries, logo, signature, cachet, textes de l'autorité et numéro vert : ceux figés sur la
 * carte à son émission (réimpression à l'identique), sinon ceux en vigueur, qui sont alors figés.
 */
class RenduCarte
{
    /** Format ISO/CEI 7810 ID-1 (85,6 × 54 mm) à 300 dpi. */
    public const LARGEUR = 1011;

    public const HAUTEUR = 638;

    /** Taille imprimée minimale du QR code (symbole, hors marge blanche), en mm. */
    public const QR_MM = 18;

    /** Logo au centre du QR code (logo unique de l'onglet 1) : zone carrée blanche, part de la largeur du symbole (correction H). */
    public const QR_LOGO = 0.22;

    public function __construct(private readonly NavigateurChrome $navigateur, private readonly Filigrane $filigrane) {}

    /** Matrice du QR code : uniquement l'URL de vérification (jeton aléatoire), correction H (logo au centre). */
    public function matrice(Carte $carte): ByteMatrix
    {
        return Encoder::encode($carte->urlVerification(), ErrorCorrectionLevel::H())->getMatrix();
    }

    /** QR code en SVG (affichage à l'écran, plein écran pour le scan). */
    public function qrSvg(Carte $carte, string $titre = ''): string
    {
        return $this->svgQr($this->matrice($carte), $titre, '#000', 2);
    }

    public function nomFichier(Carte $carte, string $extension, ?string $face = null): string
    {
        return 'carte-raaga-'.strtolower($carte->numero).'-v'.$carte->version.($face ? '-'.$face : '').'.'.$extension;
    }

    /** Image PNG d'une face (recto ou verso), 300 dpi. */
    public function png(Carte $carte, string $face = 'recto'): string
    {
        return $this->pngFaces($carte)[$face] ?? throw new RuntimeException("Face inconnue : {$face}.");
    }

    /** @return array{recto: string, verso: string} */
    public function pngFaces(Carte $carte): array
    {
        $images = $this->navigateur->captures($this->html($this->donnees($carte), 'png'), ['[data-face=recto]', '[data-face=verso]'], self::LARGEUR, self::HAUTEUR);

        return ['recto' => $images['[data-face=recto]'], 'verso' => $images['[data-face=verso]']];
    }

    /** PDF : une page par face, 85,6 × 54 mm. */
    public function pdf(Carte $carte): string
    {
        return $this->navigateur->pdf($this->html($this->donnees($carte), 'pdf'));
    }

    /**
     * Réglages de rendu de la carte : ceux figés à son émission ; à défaut (carte émise avant ces réglages), ceux en
     * vigueur, figés à cette première génération. Génération refusée si un réglage manque.
     *
     * @return array<string, string>
     */
    public function reglages(Carte $carte): array
    {
        if ($carte->reglages_rendu) {
            return $carte->reglages_rendu;
        }
        $instantane = ReglagesCarte::instantane() ?? throw new ReglagesCarteManquants(ReglagesCarte::manquants());
        $carte->forceFill(['reglages_rendu' => $instantane])->saveQuietly();

        return $instantane;
    }

    /**
     * Données affichées par le gabarit pour une carte réelle.
     *
     * @return array<string, mixed>
     */
    public function donnees(Carte $carte): array
    {
        $reglages = $this->reglages($carte);
        $c = $carte->contenu ?? [];
        $user = $carte->user;
        $naissance = data_get($user?->identite_validee, 'date_naissance') ?? $user?->date_naissance;
        $expire = $carte->valide_jusqu_au ? CarbonImmutable::parse($carte->valide_jusqu_au) : null;

        return [
            'type' => mb_strtoupper($carte->type->label()),
            // Personne morale : raison sociale, puis le représentant légal (nom, date de naissance et photo).
            'titulaire' => (string) ($c['raison_sociale'] ?? $c['nom'] ?? ''),
            'representant' => ! empty($c['raison_sociale']) ? ($c['nom'] ?? null) : null,
            'ne_le' => $naissance ? CarbonImmutable::parse($naissance)->format('d/m/Y') : null,
            'numero' => $carte->numero,
            'expire' => $expire?->format('d/m/Y'),
            'code' => $carte->numero.($expire ? '-'.$expire->format('my') : ''),
            'photo' => $this->photo($c),
            'qr' => $this->matrice($carte),
            ...$this->images($reglages),
            'qualite' => $reglages['qualite'],
            'nom_autorite' => $reglages['nom'],
            'numero_vert' => Telephone::formater($reglages['numero_vert']),
            'specimen' => false,
        ];
    }

    /**
     * Données de l'aperçu du back-office : titulaire fictif marqué SPÉCIMEN, réglages saisis (même non enregistrés).
     * Un réglage absent laisse sa place vide ; rien n'est enregistré.
     *
     * @param  array<string, mixed>  $valeurs  valeurs de la section « carte » (formulaire)
     * @param  array<string, string|null>  $images  champ => chemin absolu (image choisie ou en vigueur)
     * @return array<string, mixed>
     */
    public function specimen(array $valeurs, array $images, TypeCarte $type = TypeCarte::Commercant): array
    {
        $expire = CarbonImmutable::today()->addYears(5);
        $chemin = fn (?string $relatif) => ReglagesCarte::chemin($relatif);
        // Armoiries et logo : l'image affichée par le champ (déposée, sinon fournie avec le code).
        $armoiries = ImagesPlateforme::chemin('armoiries');
        $numeroVert = plateforme('contacts.numeroVert');
        // Filigranes : image choisie dans le formulaire (convertie sans rien enregistrer) ou celle en vigueur.
        $filigranes = [];
        foreach (Catalogue::FILIGRANES as $champ => $opacite) {
            $source = $images[$champ] ?? null;
            $filigranes[$champ] = $source ? 'data:image/png;base64,'.base64_encode($this->filigrane->png($source, $opacite)) : null;
        }

        return [
            'type' => mb_strtoupper($type->label()),
            'titulaire' => __('carte.specimen'),
            'representant' => null,
            'ne_le' => '01/01/1990',
            'numero' => 'RG-C-000000',
            'expire' => $expire->format('d/m/Y'),
            'code' => 'RG-C-000000-'.$expire->format('my'),
            'photo' => null,
            'qr' => Encoder::encode(route('cartes.verifier', str_repeat('0', 64)), ErrorCorrectionLevel::H())->getMatrix(),
            'armoiries' => $this->dataUri($armoiries),
            'logo' => $this->dataUri(ImagesPlateforme::chemin('logoHorizontal')),
            'filigranes' => $filigranes,
            'signature' => $this->dataUri($images['signatureAutorite'] ?? null),
            'cachet' => $this->dataUri($images['cachetAutorite'] ?? null),
            'qualite' => $valeurs['qualiteAutorite'] ?? null,
            'nom_autorite' => $valeurs['nomAutorite'] ?? null,
            'numero_vert' => $numeroVert ? Telephone::formater($numeroVert) : null,
            'specimen' => true,
        ];
    }

    /**
     * Document HTML autonome du rendu : « png » (mis à l'échelle, 1 px CSS = 1 px à 300 dpi) ou « pdf » (@page en mm).
     *
     * @param  array<string, mixed>  $donnees
     */
    public function html(array $donnees, string $mode): string
    {
        return view('cartes.document', [
            'd' => $donnees,
            'mode' => $mode,
            'styles' => (string) file_get_contents(public_path('css/carte-raaga.css')),
            'polices' => collect([400, 600, 700, 800])->mapWithKeys(fn (int $g) => [$g => $this->dataUri(public_path("fonts/public-sans-{$g}.woff2"), 'font/woff2')])->all(),
            // 85,6 mm en px CSS (96 par pouce) ramenés à 1011 px.
            'echelle' => self::LARGEUR / (85.6 * 96 / 25.4),
        ])->render();
    }

    /** QR code en SVG avec sa marge blanche (en modules) ; une unité = un module. */
    public function svgQr(ByteMatrix $m, string $titre = '', string $couleur = '#1E1A14', int $marge = 0): string
    {
        $n = $m->getWidth();
        $chemin = '';
        for ($y = 0; $y < $n; $y++) {
            for ($x = 0; $x < $n; $x++) {
                if ($m->get($x, $y) === 1) {
                    $chemin .= 'M'.($x + $marge).' '.($y + $marge).'h1v1h-1z';
                }
            }
        }
        $t = $n + 2 * $marge;

        return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 '.$t.' '.$t.'" shape-rendering="crispEdges" role="img" aria-label="'.e($titre).'">'
            .'<rect width="'.$t.'" height="'.$t.'" fill="#fff"/><path fill="'.$couleur.'" d="'.$chemin.'"/></svg>';
    }

    /**
     * Images figées : armoiries, logo, signature, cachet, et les filigranes (facultatifs, convertis en or à l'opacité de
     * leur rang), intégrées au document.
     *
     * @param  array<string, string|null>  $reglages
     * @return array<string, mixed>
     */
    private function images(array $reglages): array
    {
        $chemins = [];
        foreach (['armoiries', 'logo', 'signature', 'cachet'] as $cle) {
            $chemins[$cle] = ReglagesCarte::chemin($reglages[$cle] ?? null)
                ?? throw new RuntimeException("Rendu de la carte : fichier introuvable ({$cle} : {$reglages[$cle]}).");
        }

        $filigranes = [];
        foreach (Catalogue::FILIGRANES as $champ => $opacite) {
            // Carte émise avant les filigranes, ou filigrane vide à l'émission : rien n'est affiché.
            $source = ReglagesCarte::chemin($reglages[$champ] ?? null);
            $filigranes[$champ] = $source ? $this->dataUri($this->filigrane->chemin($source, $opacite)) : null;
        }

        return [
            'armoiries' => $this->dataUri($chemins['armoiries']),
            'logo' => $this->dataUri($chemins['logo']),
            'filigranes' => $filigranes,
            'signature' => $this->dataUri($chemins['signature']),
            'cachet' => $this->dataUri($chemins['cachet']),
        ];
    }

    /** Photo du titulaire : le selfie validé du dossier d'identité, intégré au document. */
    private function photo(array $contenu): ?string
    {
        if (empty($contenu['photo'])) {
            return null;
        }
        $chemin = KycDocument::query()->whereKey($contenu['photo'])->value('chemin_fichier');
        $donnees = $chemin ? Storage::disk(VerificationIdentite::DISQUE)->get($chemin) : null;

        return $donnees ? 'data:'.(new \finfo(FILEINFO_MIME_TYPE))->buffer($donnees).';base64,'.base64_encode($donnees) : null;
    }

    private function dataUri(?string $chemin, ?string $type = null): ?string
    {
        if (! $chemin || ! is_file($chemin)) {
            return null;
        }
        $contenu = (string) file_get_contents($chemin);
        $type ??= str_ends_with(strtolower($chemin), '.svg') ? 'image/svg+xml' : (new \finfo(FILEINFO_MIME_TYPE))->buffer($contenu);

        return 'data:'.$type.';base64,'.base64_encode($contenu);
    }
}
