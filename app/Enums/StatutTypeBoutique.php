<?php

namespace App\Enums;

/**
 * Statut d'un élément de référentiel (type de boutique, niveau, zone, unité) : utilisable seulement validé.
 * Brouillon : retiré de la validation par son auteur, à soumettre de nouveau.
 */
enum StatutTypeBoutique: string
{
    case Brouillon = 'BROUILLON';
    case AValider = 'A_VALIDER';
    case Valide = 'VALIDE';
    case Rejete = 'REJETE';

    public function label(): string
    {
        return __('admin.types.statuts.'.$this->value);
    }
}
