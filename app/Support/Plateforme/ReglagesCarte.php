<?php

namespace App\Support\Plateforme;

use App\Support\ImagesPlateforme;
use Illuminate\Support\Facades\Storage;

/**
 * Réglages de la carte d'identification (section « carte » des paramètres de la plateforme) : état « prête à être
 * générée » ou liste des réglages manquants, images de l'autorité intégrées au rendu. La signature et le cachet sont
 * sur le disque privé et ne sont servis par aucune adresse : ils n'apparaissent que dans le rendu des cartes.
 */
final class ReglagesCarte
{
    /**
     * Réglages requis pour générer une carte, dans l'ordre d'affichage : [section, champ]. Armoiries et logo (le logo
     * unique de l'onglet 1, aussi au centre du QR code) : l'image affichée par le champ, fichier déposé ou, à défaut,
     * fichier fourni avec le code (décision du 30/10/2026). Numéro vert : celui des contacts d'assistance. Les filigranes
     * sont facultatifs.
     */
    public const REQUIS = [
        ['identite', 'armoiries'],
        ['identite', 'logoHorizontal'],
        ['carte', 'qualiteAutorite'],
        ['carte', 'nomAutorite'],
        ['carte', 'signatureAutorite'],
        ['carte', 'cachetAutorite'],
        ['contacts', 'numeroVert'],
    ];

    /**
     * Champs manquants (clés de traduction admin.plateforme.champs).
     *
     * @param  array<string, mixed>|null  $carte  valeurs de la section « carte » (par défaut : celles en vigueur)
     * @param  array<string, string>  $nouvelles  images choisies mais pas encore enregistrées (aperçu) : champ => chemin
     * @return list<string>
     */
    public static function manquants(?array $carte = null, array $nouvelles = []): array
    {
        $carte ??= plateforme('carte');
        $manquants = [];
        foreach (self::REQUIS as [$section, $champ]) {
            $present = match (true) {
                $section === 'identite' => ImagesPlateforme::disponible($champ),
                $section === 'contacts' => filled(plateforme('contacts.'.$champ)),
                isset(Catalogue::IMAGES_CARTE[$champ]) => isset($nouvelles[$champ]) || self::chemin($carte[$champ] ?? null) !== null,
                default => filled($carte[$champ] ?? null),
            };
            if (! $present) {
                $manquants[] = $champ;
            }
        }

        return $manquants;
    }

    public static function prete(): bool
    {
        return self::manquants() === [];
    }

    /**
     * Filigranes non renseignés : avertissement non bloquant (un filigrane vide n'affiche rien sur la carte).
     *
     * @param  array<string, mixed>|null  $carte
     * @param  array<string, string>  $nouvelles  images choisies mais pas encore enregistrées
     * @return list<string> champs
     */
    public static function filigranesManquants(?array $carte = null, array $nouvelles = []): array
    {
        $carte ??= plateforme('carte');

        return array_values(array_filter(array_keys(Catalogue::FILIGRANES),
            fn (string $champ) => ! isset($nouvelles[$champ]) && self::chemin($carte[$champ] ?? null) === null));
    }

    /**
     * Réglages en vigueur, figés sur la carte à son émission (réimpression à l'identique) : chemins des fichiers sur le
     * disque privé (armoiries et logo fournis avec le code : « public:chemin »), textes de l'autorité et numéro vert tel
     * qu'enregistré. Null si un réglage manque.
     *
     * @return array<string, string|null>|null
     */
    public static function instantane(): ?array
    {
        if (! self::prete()) {
            return null;
        }

        return [
            'armoiries' => self::image('armoiries'),
            'logo' => self::image('logoHorizontal'),
            'signature' => plateforme('carte.signatureAutorite'),
            'cachet' => plateforme('carte.cachetAutorite'),
            'qualite' => plateforme('carte.qualiteAutorite'),
            'nom' => plateforme('carte.nomAutorite'),
            'numero_vert' => plateforme('contacts.numeroVert'),
            // Filigranes (facultatifs) : chemin du fichier déposé, ou null.
            ...collect(Catalogue::FILIGRANES)->mapWithKeys(fn ($o, string $champ) => [$champ => self::chemin(plateforme('carte.'.$champ)) ? plateforme('carte.'.$champ) : null])->all(),
        ];
    }

    /** Image de l'onglet 1 à figer : fichier déposé (chemin sur le disque privé), sinon fichier fourni (« public:… »). */
    private static function image(string $champ): ?string
    {
        if (ImagesPlateforme::deposee($champ)) {
            return plateforme('identite.'.$champ);
        }
        $defaut = ImagesPlateforme::DEFAUTS[$champ][0] ?? null;

        return $defaut ? 'public:'.$defaut : null;
    }

    /**
     * Chemin absolu d'un fichier déposé (chemin relatif au disque privé) ou fourni avec le code (« public:chemin »),
     * ou null s'il n'existe pas.
     */
    public static function chemin(?string $relatif): ?string
    {
        if ($relatif && str_starts_with($relatif, 'public:')) {
            $fichier = public_path(substr($relatif, 7));

            return is_file($fichier) ? $fichier : null;
        }
        $disque = Storage::disk('local');

        return $relatif && $disque->exists($relatif) ? $disque->path($relatif) : null;
    }

    /** Image intégrée à la page (aucune adresse publique) : data URI PNG, ou null. */
    public static function dataUri(?string $cheminAbsolu): ?string
    {
        return $cheminAbsolu && is_file($cheminAbsolu) ? 'data:image/png;base64,'.base64_encode((string) file_get_contents($cheminAbsolu)) : null;
    }

    /**
     * Le PNG a-t-il des pixels transparents ? Échantillonnage sur une grille (au plus 100 × 100 points) : une signature
     * ou un cachet posé sur fond transparent en a toujours. Sert à un avertissement, jamais à un refus.
     */
    public static function transparente(string $cheminAbsolu): bool
    {
        $image = @imagecreatefrompng($cheminAbsolu);
        if ($image === false) {
            return false;
        }
        $largeur = imagesx($image);
        $hauteur = imagesy($image);
        $pasX = max(1, intdiv($largeur, 100));
        $pasY = max(1, intdiv($hauteur, 100));
        $truecolor = imageistruecolor($image);
        for ($y = 0; $y < $hauteur; $y += $pasY) {
            for ($x = 0; $x < $largeur; $x += $pasX) {
                $couleur = imagecolorat($image, $x, $y);
                $alpha = $truecolor ? ($couleur >> 24) & 0x7F : imagecolorsforindex($image, $couleur)['alpha'];
                if ($alpha > 0) {
                    return true;
                }
            }
        }

        return false;
    }
}
