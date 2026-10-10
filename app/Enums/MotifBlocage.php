<?php

namespace App\Enums;

enum MotifBlocage: string
{
    case Succession = 'SUCCESSION';
    case Litige = 'LITIGE';

    public function label(): string
    {
        return match ($this) {
            self::Succession => 'Succession en cours',
            self::Litige => 'Litige',
        };
    }
}
