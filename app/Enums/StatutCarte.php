<?php

namespace App\Enums;

/** Statut d'une carte : seule une carte active est acceptée au scan. */
enum StatutCarte: string
{
    case EnAttente = 'EN_ATTENTE';
    case Active = 'ACTIVE';
    case Suspendue = 'SUSPENDUE';
    case Revoquee = 'REVOQUEE';
    case Expiree = 'EXPIREE';

    public function label(): string
    {
        return __('public.cartes.statuts.'.$this->value);
    }

    /** Carte encore rattachée à son titulaire (en attente, active ou suspendue) ; révoquée et expirée sont définitives. */
    public function courante(): bool
    {
        return in_array($this, [self::EnAttente, self::Active, self::Suspendue], true);
    }
}
