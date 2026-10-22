<?php

namespace App\Contracts;

use App\Models\Paiement;

/**
 * Fournisseur de paiement (opérateur Mobile Money, banque…). Le fournisseur ne fait que transmettre
 * la demande : la confirmation passe toujours par App\Services\Paiement\ServicePaiement::confirmer(),
 * idempotente sur la référence du paiement.
 */
interface PaymentProvider
{
    /** Vrai pour le simulateur de paiement (boutons de réponse simulée affichés, jamais en production). */
    public function estSimule(): bool;

    /** Transmet la demande de paiement (paiement au statut EN_ATTENTE, référence unique). */
    public function initier(Paiement $paiement): void;
}
