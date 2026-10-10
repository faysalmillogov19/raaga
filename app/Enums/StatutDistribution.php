<?php

namespace App\Enums;

enum StatutDistribution: string
{
    case Brouillon = 'BROUILLON';
    case Soumis = 'SOUMIS';
    case Confirme = 'CONFIRME';
    case EnPaiement = 'EN_PAIEMENT';
    case Paye = 'PAYE';
    case Annule = 'ANNULE';

    public function label(): string
    {
        return match ($this) {
            self::Brouillon => 'Brouillon',
            self::Soumis => 'Soumis à confirmation',
            self::Confirme => 'Confirmé',
            self::EnPaiement => 'En paiement',
            self::Paye => 'Payé',
            self::Annule => 'Annulé',
        };
    }
}
