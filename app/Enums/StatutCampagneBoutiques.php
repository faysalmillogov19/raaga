<?php

namespace App\Enums;

enum StatutCampagneBoutiques: string
{
    case Brouillon = 'BROUILLON';
    case Soumise = 'SOUMISE';
    case Confirmee = 'CONFIRMEE';
    case Ouverte = 'OUVERTE';
    case Cloturee = 'CLOTUREE';
    case Attribuee = 'ATTRIBUEE';

    public function label(): string
    {
        return match ($this) {
            self::Brouillon => 'Brouillon',
            self::Soumise => 'Soumise à confirmation',
            self::Confirmee => 'Confirmée',
            self::Ouverte => 'Ouverte aux candidatures',
            self::Cloturee => 'Clôturée',
            self::Attribuee => 'Boutiques attribuées',
        };
    }
}
