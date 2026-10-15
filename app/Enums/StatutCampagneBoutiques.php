<?php

namespace App\Enums;

enum StatutCampagneBoutiques: string
{
    case Brouillon = 'BROUILLON';
    case Soumise = 'SOUMISE';
    case Confirmee = 'CONFIRMEE';
    case Ouverte = 'OUVERTE';
    case Cloturee = 'CLOTUREE';
    case Tiree = 'TIREE';
    case Attribuee = 'ATTRIBUEE';

    public function label(): string
    {
        return match ($this) {
            self::Brouillon => 'Brouillon',
            self::Soumise => 'Soumise à confirmation',
            self::Confirmee => 'Confirmée',
            self::Ouverte => 'Ouverte aux candidatures',
            self::Cloturee => 'Clôturée',
            self::Tiree => 'Candidats tirés au sort, boutiques à affecter',
            self::Attribuee => 'Boutiques attribuées',
        };
    }
}
