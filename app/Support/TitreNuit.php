<?php

namespace App\Support;

/**
 * Titres « léger + gras » du thème « nuit » : le texte reste celui de lang/fr (ou de la base), seule la mise en forme
 * change. Le dernier mot (ou les $mots derniers mots) passe en gras.
 */
final class TitreNuit
{
    /** @return array{0: string, 1: string} [partie légère, partie grasse] */
    public static function decouper(string $titre, int $mots = 1): array
    {
        $parties = preg_split('/\s+/u', trim($titre)) ?: [];
        if (count($parties) <= $mots) {
            return ['', trim($titre)];
        }

        return [implode(' ', array_slice($parties, 0, -$mots)), implode(' ', array_slice($parties, -$mots))];
    }
}
