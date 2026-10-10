<?php

namespace App\Enums;

enum StatutInstitution: string
{
    case Invitee = 'INVITEE';
    case DossierEnCours = 'DOSSIER_EN_COURS';
    case Verifiee = 'VERIFIEE';
    case Active = 'ACTIVE';
    case Suspendue = 'SUSPENDUE';

    public function label(): string
    {
        return match ($this) {
            self::Invitee => 'Invitée',
            self::DossierEnCours => 'Dossier en cours',
            self::Verifiee => 'Vérifiée',
            self::Active => 'Active',
            self::Suspendue => 'Suspendue',
        };
    }
}
