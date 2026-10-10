<?php

namespace App\Enums;

enum ModeSaisie: string
{
    case NbActions = 'NB_ACTIONS';
    case MontantArrondiInf = 'MONTANT_ARRONDI_INF';
    case MontantReliquatRembourse = 'MONTANT_RELIQUAT_REMBOURSE';

    public function label(): string
    {
        return match ($this) {
            self::NbActions => 'Nombre d\'actions',
            self::MontantArrondiInf => 'Montant arrondi à l\'action inférieure',
            self::MontantReliquatRembourse => 'Montant, reliquat remboursé',
        };
    }
}
