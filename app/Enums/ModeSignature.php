<?php

namespace App\Enums;

enum ModeSignature: string
{
    case Electronique = 'ELECTRONIQUE';
    case Depot = 'DEPOT';

    public function label(): string
    {
        return match ($this) {
            self::Electronique => 'Signature électronique (code TOTP)',
            self::Depot => 'Dépôt de la convention signée (PDF)',
        };
    }
}
