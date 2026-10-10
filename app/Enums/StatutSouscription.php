<?php

namespace App\Enums;

enum StatutSouscription: string
{
    case Reservee = 'RESERVEE';
    case Payee = 'PAYEE';
    case Allouee = 'ALLOUEE';
    case Expiree = 'EXPIREE';
    case Annulee = 'ANNULEE';
    case Remboursee = 'REMBOURSEE';

    public function label(): string
    {
        return match ($this) {
            self::Reservee => 'Réservée',
            self::Payee => 'Payée',
            self::Allouee => 'Allouée',
            self::Expiree => 'Expirée',
            self::Annulee => 'Annulée',
            self::Remboursee => 'Remboursée',
        };
    }
}
