<?php

namespace App\Enums;

enum TypeDocumentKyc: string
{
    // Pièces d'identité acceptées (personne physique, représentant légal d'une personne morale).
    case Cnib = 'CNIB';
    case CibAes = 'CIB_AES';
    case Passeport = 'PASSEPORT';
    // Personne morale : attestation IFU (identifiant financier unique, anciennement « NIF »), justificatif de pouvoir du
    // représentant légal (statuts, PV de nomination ou procuration). RCCM : plus demandé depuis le 03/11/2026, gardé
    // seulement pour lire les pièces déjà déposées.
    case Rccm = 'RCCM';
    case Ifu = 'IFU';
    case JustifPouvoir = 'JUSTIF_POUVOIR';
    case Selfie = 'SELFIE';
    case JustifFonds = 'JUSTIF_FONDS';
    // Justificatif du groupe sanguin (carte de groupe, analyse) : facultatif, hors du dossier d'identité ; validé par
    // l'ADMIN, il conditionne l'impression du groupe sur la carte de commerçant (réunion du 05/11/2026).
    case GroupeSanguin = 'GROUPE_SANGUIN';

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
