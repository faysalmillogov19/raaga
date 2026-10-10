<?php

namespace App\Enums;

enum CanalPaiement: string
{
    case OrangeMoney = 'ORANGE_MONEY';
    case MoovMoney = 'MOOV_MONEY';
    case Virement = 'VIREMENT';
    case Especes = 'ESPECES';

    public function label(): string
    {
        return match ($this) {
            self::OrangeMoney => 'Orange Money',
            self::MoovMoney => 'Moov Money',
            self::Virement => 'Virement bancaire',
            self::Especes => 'Espèces',
        };
    }
}
