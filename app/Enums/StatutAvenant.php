<?php

namespace App\Enums;

enum StatutAvenant: string
{
    case EnAttente = 'EN_ATTENTE';
    case Approuve = 'APPROUVE';
    case Rejete = 'REJETE';

    public function label(): string
    {
        return match ($this) {
            self::EnAttente => 'Soumis à confirmation',
            self::Approuve => 'Confirmé',
            self::Rejete => 'Rejeté',
        };
    }
}
