<?php

namespace App\Enums;

enum TypeDocumentKyc: string
{
    case Cnib = 'CNIB';
    case Passeport = 'PASSEPORT';
    // Numéro d'identification fiscale (attestation), et registre du commerce pour les entreprises (facultatif).
    case Nif = 'NIF';
    case Rccm = 'RCCM';
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
