<?php

namespace App\Enums;

enum FaceDocument: string
{
    case Recto = 'RECTO';
    case Verso = 'VERSO';

    public function label(): string
    {
        return match ($this) {
            self::Recto => 'Recto',
            self::Verso => 'Verso',
        };
    }
}
