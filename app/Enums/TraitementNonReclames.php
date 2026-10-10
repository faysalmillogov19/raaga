<?php

namespace App\Enums;

enum TraitementNonReclames: string
{
    case Reserves = 'RESERVES';
    case Consignation = 'CONSIGNATION';

    public function label(): string
    {
        return match ($this) {
            self::Reserves => 'Réintégration aux réserves de Raaga SA',
            self::Consignation => 'Consignation auprès de l’organisme désigné par la loi',
        };
    }
}
