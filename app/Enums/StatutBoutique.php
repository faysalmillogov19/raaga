<?php

namespace App\Enums;

/**
 * Statut commercial d'une boutique. Réservation au premier arrivé : disponible → pré-réservée (caution à payer) →
 * réservée (caution payée) → attribuée (avance payée et contrat signé) ; une pré-réservation expirée ou annulée remet
 * la boutique disponible. « Dans une campagne » : ancien tirage au sort (historique). Chaque changement est conservé
 * dans boutique_statuts.
 */
enum StatutBoutique: string
{
    case Disponible = 'DISPONIBLE';
    case PreReservee = 'PRE_RESERVEE';
    case Reservee = 'RESERVEE';
    case Attribuee = 'ATTRIBUEE';
    case EnCampagne = 'EN_CAMPAGNE';
    case Retiree = 'RETIREE';

    public function label(): string
    {
        return match ($this) {
            self::Disponible => 'Disponible',
            self::PreReservee => 'Pré-réservée',
            self::Reservee => 'Réservée',
            self::Attribuee => 'Attribuée',
            self::EnCampagne => 'Dans une campagne (ancien tirage)',
            self::Retiree => 'Retirée',
        };
    }

    /** Une boutique engagée (réservation en cours ou attribuée) ne se modifie ni ne se supprime ; l'ancien statut « dans une campagne » vaut disponible. */
    public function verrouillee(): bool
    {
        return in_array($this, [self::PreReservee, self::Reservee, self::Attribuee], true);
    }

    /** Réservable au premier arrivé. */
    public function reservable(): bool
    {
        return in_array($this, [self::Disponible, self::EnCampagne], true);
    }

    /** Statut affiché sur le plan public : disponible, pré-réservée, réservée ou attribuée (jamais de donnée personnelle). */
    public function surPlan(): string
    {
        return match ($this) {
            self::Disponible, self::EnCampagne => 'disponible',
            self::PreReservee => 'pre_reservee',
            self::Reservee => 'reservee',
            self::Attribuee => 'attribuee',
            self::Retiree => 'retiree',
        };
    }
}
