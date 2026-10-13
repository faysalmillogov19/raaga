<?php

namespace App\Enums;

enum StatutSite: string
{
    case Brouillon = 'BROUILLON';
    case Soumis = 'SOUMIS';
    case Confirme = 'CONFIRME';

    public function label(): string
    {
        return match ($this) {
            self::Brouillon => 'Brouillon',
            self::Soumis => 'Soumis à confirmation',
            self::Confirme => 'Confirmé',
        };
    }
}
