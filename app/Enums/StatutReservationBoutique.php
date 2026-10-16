<?php

namespace App\Enums;

/**
 * Réservation d'une boutique, au premier arrivé : pré-réservée (caution à payer dans le délai), réservée (caution
 * payée), attribuée (avance payée et contrat signé), expirée (délai dépassé sans paiement) ou annulée (identité
 * douteuse confirmée). Les statuts « historique » viennent de l'ancien tirage au sort : conservés en lecture seule.
 */
enum StatutReservationBoutique: string
{
    case PreReservee = 'PRE_RESERVEE';
    case Reservee = 'RESERVEE';
    case Attribuee = 'ATTRIBUEE';
    case Expiree = 'EXPIREE';
    // Identité douteuse confirmée par le VALIDATEUR : réservation annulée, boutique libérée.
    case Annulee = 'ANNULEE';

    // Historique de l'ancien tirage au sort (lecture seule, jamais créés).
    case Payee = 'PAYEE';
    case Retenue = 'RETENUE';
    case NonRetenue = 'NON_RETENUE';

    public function label(): string
    {
        return match ($this) {
            self::PreReservee => 'Pré-réservée : caution à payer',
            self::Reservee => 'Réservée : caution payée',
            self::Attribuee => 'Attribuée',
            self::Expiree => 'Expirée (caution non payée)',
            self::Annulee => 'Annulée',
            self::Payee => 'Caution payée (ancien tirage)',
            self::Retenue => 'Retenue au tirage (ancien tirage)',
            self::NonRetenue => 'Non retenue (ancien tirage)',
        };
    }

    /** Réservations qui occupent une boutique : une seule par boutique (index unique partiel en base). @return list<self> */
    public static function actifs(): array
    {
        return [self::PreReservee, self::Reservee, self::Attribuee];
    }

    /** @return list<string> */
    public static function valeursActives(): array
    {
        return array_map(fn (self $s) => $s->value, self::actifs());
    }
}
