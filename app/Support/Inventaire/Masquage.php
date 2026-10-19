<?php

namespace App\Support\Inventaire;

/**
 * Masquage des données personnelles et secrets dans les inventaires : adresses électroniques, numéros de téléphone,
 * identifiants longs (NIP, IFU, numéros de pièce) et valeurs de type clé ou jeton. Les nombres affichés par
 * l'inventaire sont groupés par espaces fines insécables, ils ne sont donc jamais pris pour un identifiant.
 */
final class Masquage
{
    public const MASQUE = '[MASQUÉ]';

    private const MOTIFS = [
        // Adresses électroniques.
        '/[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}/u',
        // Téléphones burkinabè : +226 ou 00226 facultatif, puis 8 chiffres groupés par 2 ou collés.
        '/(?<![\w])(?:(?:\+|00)226[ .-]?)?\d{2}(?:[ .-]?\d{2}){3}(?![\w])/u',
        // Identifiants longs (NIP, IFU, numéros de pièce) : 8 chiffres ou plus, lettre finale facultative.
        '/(?<![\w])[A-Z]{0,2}\d{8,}[A-Z]?(?![\w])/u',
        // Valeurs de type clé, jeton ou empreinte : 32 caractères hexadécimaux ou base64 au moins.
        '/(?<![\w])(?=[A-Za-z0-9+\/_-]*\d)[A-Za-z0-9+\/_-]{32,}={0,2}(?![\w])/u',
    ];

    public static function texte(string $texte): string
    {
        return (string) preg_replace(self::MOTIFS, self::MASQUE, $texte);
    }
}
