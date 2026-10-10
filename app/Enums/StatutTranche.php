<?php

namespace App\Enums;

enum StatutTranche: string
{
    case Brouillon = 'BROUILLON';
    case Soumise = 'SOUMISE';
    case Confirmee = 'CONFIRMEE';
    case Ouverte = 'OUVERTE';
    case Cloturee = 'CLOTUREE';
    case Allouee = 'ALLOUEE';
    case Annulee = 'ANNULEE';

    public function label(): string
    {
        return match ($this) {
            self::Brouillon => 'Brouillon',
            self::Soumise => 'Soumise à confirmation',
            self::Confirmee => 'Confirmée',
            self::Ouverte => 'Ouverte',
            self::Cloturee => 'Clôturée',
            self::Allouee => 'Allouée',
            self::Annulee => 'Annulée',
        };
    }
}
