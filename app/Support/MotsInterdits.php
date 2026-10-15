<?php

namespace App\Support;

/**
 * Mots interdits sur tout le site public (vues, textes saisis par l'ADMIN, carrousel compris) : un placement en actions
 * comporte un risque de perte, aucun texte ne doit promettre un rendement.
 * « garanti » (sauf « non garanti »), « sans risque », « rendement de », « gagnez », un pourcentage associé à « par an »,
 * ainsi que « dividendes assurés », « gains certains »…
 */
final class MotsInterdits
{
    public const MOTIF = '/(?<!non )(?<!non\x{00A0})(?<!pas )(?<!pas\x{00A0})\bgaranti(e|s|es)?\b|\bsans\s+risques?\b|\brendements?\s+de\b|\bgagnez\b'
        .'|\d+([,.]\d+)?\s*%[^.\n]{0,20}\b(par\s+an|l[’\x27]an)\b'
        .'|\b(gains?|bénéfices?|profits?|intérêts?|dividendes?|revenus?)\s+(assurés?|certains?|sûrs?)\b/iu';

    /** Premier mot interdit trouvé dans le texte, ou null. */
    public static function trouver(?string $texte): ?string
    {
        return $texte !== null && preg_match(self::MOTIF, $texte, $m) ? $m[0] : null;
    }
}
