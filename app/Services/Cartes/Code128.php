<?php

namespace App\Services\Cartes;

use InvalidArgumentException;

/**
 * Code-barres Code 128 (ISO/IEC 15417), sans dépendance : jeu B pour le texte, jeu C pour les suites d'au moins quatre
 * chiffres (deux chiffres par symbole, code plus court), somme de contrôle modulo 103. Rendu en SVG, barres en modules.
 */
final class Code128
{
    /** Largeurs barre / espace / barre… de chaque symbole (valeurs 0 à 105), puis l'arrêt (106). */
    private const MOTIFS = [
        '212222', '222122', '222221', '121223', '121322', '131222', '122213', '122312', '132212', '221213',
        '221312', '231212', '112232', '122132', '122231', '113222', '123122', '123221', '223211', '221132',
        '221231', '213212', '223112', '312131', '311222', '321122', '321221', '312212', '322112', '322211',
        '212123', '212321', '232121', '111323', '131123', '131321', '112313', '132113', '132311', '211313',
        '231113', '231311', '112133', '112331', '132131', '113123', '113321', '133121', '313121', '211331',
        '231131', '213113', '213311', '213131', '311123', '311321', '331121', '312113', '312311', '332111',
        '314111', '221411', '431111', '111224', '111422', '121124', '121421', '141122', '141221', '112214',
        '112412', '122114', '122411', '142112', '142211', '241211', '221114', '413111', '241112', '134111',
        '111242', '121142', '121241', '114212', '124112', '124211', '411212', '421112', '421211', '212141',
        '214121', '412121', '111143', '111341', '131141', '114113', '114311', '411113', '411311', '113141',
        '114131', '311141', '411131', '211412', '211214', '211232', '2331112',
    ];

    private const DEBUT_B = 104;

    private const DEBUT_C = 105;

    private const VERS_C = 99;

    private const VERS_B = 100;

    private const ARRET = 106;

    /**
     * Valeurs des symboles, du départ à la somme de contrôle (sans l'arrêt).
     *
     * @return list<int>
     */
    public static function symboles(string $texte): array
    {
        if ($texte === '' || preg_match('/[^\x20-\x7E]/', $texte)) {
            throw new InvalidArgumentException('Code 128 : caractères ASCII imprimables uniquement.');
        }

        // Découpage : suites d'au moins quatre chiffres en jeu C (nombre pair de chiffres), le reste en jeu B.
        $morceaux = preg_split('/(\d{4,})/', $texte, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY) ?: [];
        $valeurs = [];
        $jeu = null;
        foreach ($morceaux as $morceau) {
            if (ctype_digit($morceau) && strlen($morceau) >= 4) {
                // Nombre impair de chiffres : le premier reste en jeu B.
                if (strlen($morceau) % 2 === 1) {
                    self::ajouterB($valeurs, $jeu, $morceau[0]);
                    $morceau = substr($morceau, 1);
                }
                if ($jeu === null) {
                    $valeurs[] = self::DEBUT_C;
                } elseif ($jeu !== 'C') {
                    $valeurs[] = self::VERS_C;
                }
                $jeu = 'C';
                foreach (str_split($morceau, 2) as $paire) {
                    $valeurs[] = (int) $paire;
                }

                continue;
            }
            self::ajouterB($valeurs, $jeu, $morceau);
        }

        $somme = $valeurs[0];
        foreach (array_slice($valeurs, 1) as $i => $v) {
            $somme += ($i + 1) * $v;
        }
        $valeurs[] = $somme % 103;

        return $valeurs;
    }

    /** @param list<int> $valeurs */
    private static function ajouterB(array &$valeurs, ?string &$jeu, string $texte): void
    {
        if ($jeu === null) {
            $valeurs[] = self::DEBUT_B;
        } elseif ($jeu !== 'B') {
            $valeurs[] = self::VERS_B;
        }
        $jeu = 'B';
        foreach (str_split($texte) as $c) {
            $valeurs[] = ord($c) - 32;
        }
    }

    /**
     * Largeurs successives (barre, espace, barre…) en modules, arrêt compris.
     *
     * @return list<int>
     */
    public static function largeurs(string $texte): array
    {
        $largeurs = [];
        foreach ([...self::symboles($texte), self::ARRET] as $valeur) {
            foreach (str_split(self::MOTIFS[$valeur]) as $l) {
                $largeurs[] = (int) $l;
            }
        }

        return $largeurs;
    }

    /** Code-barres en SVG (une unité = un module), à étirer en largeur par le gabarit. */
    public static function svg(string $texte, string $couleur = '#1E1A14', string $libelle = ''): string
    {
        $x = 0;
        $chemin = '';
        foreach (self::largeurs($texte) as $i => $l) {
            if ($i % 2 === 0) {
                $chemin .= 'M'.$x.' 0h'.$l.'v60h-'.$l.'z';
            }
            $x += $l;
        }

        return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 '.$x.' 60" preserveAspectRatio="none" shape-rendering="crispEdges" role="img" aria-label="'.e($libelle).'">'
            .'<path d="'.$chemin.'" fill="'.$couleur.'"/></svg>';
    }
}
