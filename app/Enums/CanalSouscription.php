<?php

namespace App\Enums;

enum CanalSouscription: string
{
    case Web = 'WEB';
    case Guichet = 'GUICHET';

    public function label(): string
    {
        return match ($this) {
            self::Web => 'Web',
            self::Guichet => 'Guichet',
        };
    }
}
