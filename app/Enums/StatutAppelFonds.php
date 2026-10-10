<?php

namespace App\Enums;

enum StatutAppelFonds: string
{
    case APayer = 'A_PAYER';
    case Declare = 'DECLARE';
    case Partiel = 'PARTIEL';
    case Recu = 'RECU';
    case EnRetard = 'EN_RETARD';

    public function label(): string
    {
        return match ($this) {
            self::APayer => 'À payer',
            self::Declare => 'Virement déclaré',
            self::Partiel => 'Reçu partiellement',
            self::Recu => 'Reçu',
            self::EnRetard => 'En retard',
        };
    }
}
