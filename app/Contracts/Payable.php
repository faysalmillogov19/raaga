<?php

namespace App\Contracts;

use App\Enums\CompteDestination;
use App\Models\MoyenPaiement;
use App\Models\Paiement;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Objet réglé par le module de paiement : souscription d'actions (réservée → payée) ou caution d'une
 * candidature aux boutiques (réservée → payée). Le service de paiement ne connaît que ce contrat.
 */
interface Payable
{
    /** @return HasMany<Paiement, Model> */
    public function paiements(): HasMany;

    /** Colonne de la table paiements qui pointe vers cet objet. */
    public static function clePaiement(): string;

    public function montantAPayer(): int;

    public function fraisAPayer(): int;

    /** En attente de paiement (statut RÉSERVÉE). */
    public function attendPaiement(): bool;

    /** Passage au statut payé (appelé dans la transaction de confirmation). */
    public function marquerPaye(): void;

    /** Expiré faute de paiement dans le délai. */
    public function estExpire(): bool;

    /** Compte qui reçoit le paiement : investissement (actions) ou location (caution, avance, loyers). */
    public function compteDestination(): CompteDestination;

    /** @return list<MoyenPaiement> moyens de paiement actifs selon les paramètres confirmés (validés et actifs au référentiel) */
    public function canauxPaiement(): array;

    /**
     * SMS envoyé après paiement confirmé : [modèle, variables].
     *
     * @return array{0: string, 1: array<string, string>}
     */
    public function smsPaiementConfirme(Paiement $paiement): array;
}
