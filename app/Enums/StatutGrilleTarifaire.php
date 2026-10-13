<?php

namespace App\Enums;

/** Statut d'une grille tarifaire (workflow commun : RÉGULATEUR saisit, VALIDATEUR confirme). */
enum StatutGrilleTarifaire: string
{
    case Brouillon = 'BROUILLON';
    case Soumis = 'SOUMIS';
    case Confirme = 'CONFIRME';

    public function label(): string
    {
        return __('admin.grilles.statuts.'.$this->value);
    }
}
