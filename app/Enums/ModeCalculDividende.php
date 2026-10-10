<?php

namespace App\Enums;

enum ModeCalculDividende: string
{
    case MontantGlobal = 'MONTANT_GLOBAL';
    case ParAction = 'PAR_ACTION';

    public function label(): string
    {
        return match ($this) {
            self::MontantGlobal => 'Montant global divisé par le nombre d’actions',
            self::ParAction => 'Dividende brut par action saisi directement',
        };
    }
}
