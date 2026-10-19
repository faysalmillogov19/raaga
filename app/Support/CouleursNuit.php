<?php

namespace App\Support;

/**
 * Couleurs de pastille du thème « nuit » (mêmes valeurs que les classes .nuit-c-* de public/css/raaga.css), pour les
 * graphiques en CSS pur dont les proportions sont calculées côté serveur (anneau).
 */
final class CouleursNuit
{
    public const HEX = [
        'vert' => '#00A86B',
        'bleu' => '#1E9BF0',
        'orange' => '#F7931E',
        'rose' => '#E04FB0',
        'cyan' => '#18B4C9',
        'rouge' => '#E8453C',
        'jaune' => '#FCD116',
        'vif' => '#3DDC97',
        'gris' => '#8FA3B8',
    ];

    /** Ordre par défaut des pastilles (frises, graphiques, étapes). */
    public const CYCLE = ['vert', 'bleu', 'orange', 'rose', 'cyan', 'rouge'];

    public static function nom(?string $couleur, int $index = 0): string
    {
        return isset(self::HEX[$couleur ?? '']) ? $couleur : self::CYCLE[$index % count(self::CYCLE)];
    }
}
