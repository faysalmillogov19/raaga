<?php

namespace App\Enums;

enum DocumentAccepte: string
{
    case AucunDroitBoutique = 'AUCUN_DROIT_BOUTIQUE';
    case RendementNonGaranti = 'RENDEMENT_NON_GARANTI';
    case ReglementTranche = 'REGLEMENT_TRANCHE';
    case ConditionsUtilisation = 'CONDITIONS_UTILISATION';
    case OrigineLiciteFonds = 'ORIGINE_LICITE_FONDS';
    // Candidature aux boutiques.
    case ReglementCampagneBoutiques = 'REGLEMENT_CAMPAGNE_BOUTIQUES';
    // Historique de l'ancien tirage au sort (acceptations déjà enregistrées) : plus jamais demandé.
    case TirageSansGarantie = 'TIRAGE_SANS_GARANTIE';
    case CautionSansLienActions = 'CAUTION_SANS_LIEN_ACTIONS';

    public function label(): string
    {
        return match ($this) {
            self::AucunDroitBoutique => 'Absence de droit à une boutique',
            self::RendementNonGaranti => 'Rendement non garanti',
            self::ReglementTranche => 'Règlement de la tranche',
            self::ConditionsUtilisation => 'Conditions d’utilisation et protection des données personnelles',
            self::OrigineLiciteFonds => 'Origine licite des fonds',
            self::ReglementCampagneBoutiques => 'Règlement de la campagne d’attribution des boutiques',
            self::TirageSansGarantie => 'Attribution par tirage au sort, sans garantie d’obtenir une boutique',
            self::CautionSansLienActions => 'Caution sans lien avec les actions',
        };
    }
}
