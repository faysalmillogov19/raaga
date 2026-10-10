<?php

namespace App\Enums;

enum TypeDocumentKyc: string
{
    case Cnib = 'CNIB';
    case Passeport = 'PASSEPORT';
    case Selfie = 'SELFIE';
    case JustifFonds = 'JUSTIF_FONDS';

    public function label(): string
    {
        return match ($this) {
            self::Cnib => 'CNIB',
            self::Passeport => 'Passeport',
            self::Selfie => 'Selfie',
            self::JustifFonds => 'Justificatif d\'origine des fonds',
        };
    }
}
