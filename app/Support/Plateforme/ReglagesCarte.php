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
    /** Réglages requis pour générer une carte, dans l'ordre d'affichage : [section, champ]. */
    public const REQUIS = [
        ['identite', 'armoiries'],
        ['identite', 'logoHorizontal'],
        ['carte', 'qualiteAutorite'],
        ['carte', 'nomAutorite'],
        ['carte', 'signatureAutorite'],
        ['carte', 'cachetAutorite'],
        ['carte', 'numeroVertCarte'],
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

    /** Chemin absolu d'un fichier déposé (chemin relatif au disque privé), ou null s'il n'existe pas. */
    public static function chemin(?string $relatif): ?string
    {
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
