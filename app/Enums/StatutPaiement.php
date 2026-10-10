<?php

namespace App\Enums;

enum StatutPaiement: string
{
    case EnAttente = 'EN_ATTENTE';
    case Confirme = 'CONFIRME';
    case Echoue = 'ECHOUE';
    case Annule = 'ANNULE';

    public function label(): string
    {
        return match ($this) {
            self::EnAttente => 'En attente',
            self::Confirme => 'Confirmé',
            self::Echoue => 'Échoué',
            self::Annule => 'Annulé',
        };
    }
}
