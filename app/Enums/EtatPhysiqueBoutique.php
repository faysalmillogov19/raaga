<?php

namespace App\Enums;

/** État physique d'une boutique, distinct de son statut commercial (StatutBoutique). */
enum EtatPhysiqueBoutique: string
{
    case EnConstruction = 'EN_CONSTRUCTION';
    case Livree = 'LIVREE';
    case EnTravaux = 'EN_TRAVAUX';
    case Fermee = 'FERMEE';

    public function label(): string
    {
        return __('admin.sites.etats_physiques.'.$this->value);
    }
}
