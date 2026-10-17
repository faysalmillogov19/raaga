<?php

namespace App\Enums;

enum TypeDocumentKyc: string
{
    // Pièces d'identité acceptées (personne physique, représentant légal d'une personne morale).
    case Cnib = 'CNIB';
    case CibAes = 'CIB_AES';
    case Passeport = 'PASSEPORT';
    // Personne morale : RCCM, attestation IFU (identifiant financier unique, anciennement « NIF »), justificatif de
    // pouvoir du représentant légal (statuts, PV de nomination ou procuration).
    case Rccm = 'RCCM';
    case Ifu = 'IFU';
    case JustifPouvoir = 'JUSTIF_POUVOIR';
    case Selfie = 'SELFIE';
    case JustifFonds = 'JUSTIF_FONDS';

    /** Pièces d'identité acceptées, et rien d'autre. @return list<self> */
    public static function piecesIdentite(): array
    {
        return [self::Cnib, self::CibAes, self::Passeport];
    }

    /** Pièce recto-verso (CNIB, CIB-AES) ; le passeport : une page. */
    public function rectoVerso(): bool
    {
        return in_array($this, [self::Cnib, self::CibAes], true);
    }

    public function label(): string
    {
        return __('public.verification.libelles.'.$this->value);
    }
}
