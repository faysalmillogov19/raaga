<?php

namespace App\Enums;

/**
 * Inscription d'un citoyen sur la liste d'attente d'une boutique : en attente (rang dans l'ordre d'arrivée), retenue
 * (le citoyen en est l'attributaire : réservation en cours ou attribuée), perdue (réservation expirée ou annulée sans
 * attribution), désistée (retrait du citoyen) ou annulée (identité douteuse confirmée).
 */
enum StatutInscriptionBoutique: string
{
    case EnAttente = 'EN_ATTENTE';
    case Retenue = 'RETENUE';
    case Perdue = 'PERDUE';
    case Desistee = 'DESISTEE';
    case Annulee = 'ANNULEE';

    public function label(): string
    {
        return __('public.boutiques.attente.statuts.'.$this->value);
    }

    /** Inscriptions qui comptent dans la liste d'une boutique : une seule par citoyen et par boutique (index unique en base). @return list<self> */
    public static function actives(): array
    {
        return [self::EnAttente, self::Retenue];
    }
}
