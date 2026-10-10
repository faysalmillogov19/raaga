<?php

namespace App\Enums;

enum ModeSignature: string
{
    case Electronique = 'ELECTRONIQUE';
    case Depot = 'DEPOT';

    /** Libellé court, à insérer dans une phrase (« signée le … par … (signature électronique) »). */
    public function court(): string
    {
        return match ($this) {
            self::Electronique => 'signature électronique',
            self::Depot => 'original signé déposé',
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Electronique => 'Signature électronique (code TOTP)',
            self::Depot => 'Dépôt de la convention signée (PDF)',
        };
    }
}
