<?php

namespace App\Enums;

/** Type de carte d'accès Raaga : une carte par personne et par type. */
enum TypeCarte: string
{
    case Commercant = 'COMMERCANT';
    case Actionnaire = 'ACTIONNAIRE';
    case Personnel = 'PERSONNEL';

    public function label(): string
    {
        return __('public.cartes.types.'.$this->value);
    }

    /** Cartes des citoyens : émises seulement si le dossier d'identité est conforme. */
    public function soumiseAuKyc(): bool
    {
        return $this !== self::Personnel;
    }
}
