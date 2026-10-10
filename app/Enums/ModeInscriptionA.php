<?php

namespace App\Enums;

enum ModeInscriptionA: string
{
    case Prorata = 'PRORATA';
    case DernierVersement = 'DERNIER_VERSEMENT';

    public function label(): string
    {
        return match ($this) {
            self::Prorata => 'Au prorata de chaque appel de fonds reçu',
            self::DernierVersement => 'En totalité au dernier versement',
        };
    }
}
