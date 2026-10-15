<?php

namespace App\Enums;

/** Paiement auquel un moyen peut servir, avec ses plafonds : souscription d'actions, caution d'une boutique. */
enum UsageMoyenPaiement: string
{
    case Actions = 'ACTIONS';
    case Caution = 'CAUTION';

    public function label(): string
    {
        return __('admin.moyens_paiement.usages.'.$this->value);
    }
}
