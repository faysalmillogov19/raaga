<?php

namespace App\Enums;

/** Statut d'un type de boutique : un type ajouté par le GESTIONNAIRE n'est utilisable qu'après validation. */
enum StatutTypeBoutique: string
{
    case AValider = 'A_VALIDER';
    case Valide = 'VALIDE';
    case Rejete = 'REJETE';

    public function label(): string
    {
        return __('admin.types.statuts.'.$this->value);
    }
}
