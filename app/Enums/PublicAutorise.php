<?php

namespace App\Enums;

enum PublicAutorise: string
{
    case ResidentBf = 'RESIDENT_BF';
    case Diaspora = 'DIASPORA';

    public function label(): string
    {
        return match ($this) {
            self::ResidentBf => 'Résidents au Burkina Faso',
            self::Diaspora => 'Diaspora',
        };
    }
}
