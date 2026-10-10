<?php

namespace App\Enums;

enum StatutKyc: string
{
    case NonSoumis = 'NON_SOUMIS';
    case EnAttente = 'EN_ATTENTE';
    case Valide = 'VALIDE';
    case Rejete = 'REJETE';

    public function label(): string
    {
        return match ($this) {
            self::NonSoumis => 'Non soumis',
            self::EnAttente => 'En attente',
            self::Valide => 'Validé',
            self::Rejete => 'Rejeté',
        };
    }
}
