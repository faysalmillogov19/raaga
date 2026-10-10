<?php

namespace App\Enums;

enum RegleSiEchec: string
{
    case RemboursementIntegral = 'REMBOURSEMENT_INTEGRAL';
    case FondsConserves = 'FONDS_CONSERVES';
    case Prolongation = 'PROLONGATION';

    public function label(): string
    {
        return match ($this) {
            self::RemboursementIntegral => 'Remboursement intégral',
            self::FondsConserves => 'Fonds conservés',
            self::Prolongation => 'Prolongation',
        };
    }
}
