<?php

namespace App\Enums;

enum MethodeAllocation: string
{
    case PremierArrive = 'PREMIER_ARRIVE';
    case Prorata = 'PRORATA';
    case PrioritePetits = 'PRIORITE_PETITS';

    public function label(): string
    {
        return match ($this) {
            self::PremierArrive => 'Premier arrivé, premier servi',
            self::Prorata => 'Au prorata',
            self::PrioritePetits => 'Priorité aux petits souscripteurs',
        };
    }
}
