<?php

namespace App\Enums;

enum StatutBoutique: string
{
    case Disponible = 'DISPONIBLE';
    case EnCampagne = 'EN_CAMPAGNE';
    case Attribuee = 'ATTRIBUEE';
    case Retiree = 'RETIREE';

    public function label(): string
    {
        return match ($this) {
            self::Disponible => 'Disponible',
            self::EnCampagne => 'Dans une campagne',
            self::Attribuee => 'Attribuée',
            self::Retiree => 'Retirée',
        };
    }

    /** Une boutique engagée (campagne en cours ou attribuée) ne se modifie ni ne se supprime. */
    public function verrouillee(): bool
    {
        return in_array($this, [self::EnCampagne, self::Attribuee], true);
    }
}
