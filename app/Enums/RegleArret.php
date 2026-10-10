<?php

namespace App\Enums;

enum RegleArret: string
{
    case ArretAObjectif = 'ARRET_A_OBJECTIF';
    case DepassementAutorise = 'DEPASSEMENT_AUTORISE';

    public function label(): string
    {
        return match ($this) {
            self::ArretAObjectif => 'Arrêt à l\'objectif',
            self::DepassementAutorise => 'Dépassement autorisé',
        };
    }
}
