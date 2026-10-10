<?php

namespace App\Enums;

enum StatutConvention: string
{
    case Brouillon = 'BROUILLON';
    case Soumise = 'SOUMISE';
    case Confirmee = 'CONFIRMEE';

    public function label(): string
    {
        return match ($this) {
            self::Brouillon => 'Brouillon',
            self::Soumise => 'Soumise à confirmation',
            self::Confirmee => 'Confirmée',
        };
    }
}
