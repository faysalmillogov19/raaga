<?php

namespace App\Enums;

enum StatutKyc: string
{
    case NonSoumis = 'NON_SOUMIS';
    case EnAttente = 'EN_ATTENTE';
    case Valide = 'VALIDE';
    case Rejete = 'REJETE';
    // Dossier ancien qui ne suit plus les règles des pièces (17/10/2026) : rien n'est supprimé, le titulaire dépose un
    // dossier complet ; aucune carte en attendant.
    case AMettreAJour = 'A_METTRE_A_JOUR';

    public function label(): string
    {
        return match ($this) {
            self::NonSoumis => 'Non soumis',
            self::EnAttente => 'En attente',
            self::Valide => 'Validé',
            self::Rejete => 'Rejeté',
            self::AMettreAJour => 'Pièces à mettre à jour',
        };
    }
}
