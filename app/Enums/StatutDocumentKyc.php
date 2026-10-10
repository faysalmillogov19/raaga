<?php

namespace App\Enums;

enum StatutDocumentKyc: string
{
    case EnAttente = 'EN_ATTENTE';
    case Valide = 'VALIDE';
    case Rejete = 'REJETE';

    public function label(): string
    {
        return match ($this) {
            self::EnAttente => 'En attente',
            self::Valide => 'Validé',
            self::Rejete => 'Rejeté',
        };
    }
}
