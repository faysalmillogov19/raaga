<?php

namespace App\Enums;

enum TypeDocumentKyb: string
{
    case Statuts = 'STATUTS';
    case ExtraitRccm = 'EXTRAIT_RCCM';
    case AttestationIfu = 'ATTESTATION_IFU';
    case ListeDirigeants = 'LISTE_DIRIGEANTS';
    case NominationRepresentants = 'NOMINATION_REPRESENTANTS';
    case DelegationSignature = 'DELEGATION_SIGNATURE';
    case DecisionInvestissement = 'DECISION_INVESTISSEMENT';
    case Rib = 'RIB';

    public function label(): string
    {
        return match ($this) {
            self::Statuts => 'Statuts',
            self::ExtraitRccm => 'Extrait RCCM',
            self::AttestationIfu => 'Attestation IFU',
            self::ListeDirigeants => 'Liste des dirigeants',
            self::NominationRepresentants => 'PV ou acte de nomination des représentants',
            self::DelegationSignature => 'Délégation de pouvoir de signature',
            self::DecisionInvestissement => 'Décision de l’organe compétent autorisant l’investissement dans Raaga',
            self::Rib => 'RIB du compte émetteur des virements',
        };
    }
}
