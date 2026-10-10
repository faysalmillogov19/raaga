<?php

namespace App\Enums;

enum TypeFraisSouscription: string
{
    case Aucun = 'AUCUN';
    case Pourcentage = 'POURCENTAGE';
    case Fixe = 'FIXE';

    public function label(): string
    {
        return match ($this) {
            self::Aucun => 'Aucun',
            self::Pourcentage => 'Pourcentage',
            self::Fixe => 'Montant fixe',
        };
    }
}
