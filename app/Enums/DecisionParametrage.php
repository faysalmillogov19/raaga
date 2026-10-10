<?php

namespace App\Enums;

enum DecisionParametrage: string
{
    case Confirme = 'CONFIRME';
    case Rejete = 'REJETE';

    public function label(): string
    {
        return match ($this) {
            self::Confirme => 'Confirmé',
            self::Rejete => 'Rejeté',
        };
    }
}
