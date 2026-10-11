<?php

namespace App\Enums;

enum DocumentAccepte: string
{
    case AucunDroitBoutique = 'AUCUN_DROIT_BOUTIQUE';
    case RendementNonGaranti = 'RENDEMENT_NON_GARANTI';
    case ReglementTranche = 'REGLEMENT_TRANCHE';
    case ConditionsUtilisation = 'CONDITIONS_UTILISATION';
    case OrigineLiciteFonds = 'ORIGINE_LICITE_FONDS';

    public function label(): string
    {
        return match ($this) {
            self::AucunDroitBoutique => 'Absence de droit à une boutique',
            self::RendementNonGaranti => 'Rendement non garanti',
            self::ReglementTranche => 'Règlement de la tranche',
            self::ConditionsUtilisation => 'Conditions d’utilisation et protection des données personnelles',
            self::OrigineLiciteFonds => 'Origine licite des fonds',
        };
    }
}
