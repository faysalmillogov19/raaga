<?php

namespace App\Enums;

enum StatutRevue: string
{
    case EnAttente = 'EN_ATTENTE';
    case Approuvee = 'APPROUVEE';
    case Rejetee = 'REJETEE';

    public function label(): string
    {
        return match ($this) {
            self::EnAttente => 'En attente de revue',
            self::Approuvee => 'Approuvée',
            self::Rejetee => 'Rejetée',
        };
    }
}
