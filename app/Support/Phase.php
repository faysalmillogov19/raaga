<?php

namespace App\Support;

/**
 * Périmètre de la phase en cours (décision de la direction, 13/10/2026) : la phase 1 ne porte que sur les
 * souscriptions aux boutiques et leurs cautions. Les parcours publics de souscription d'actions sont alors
 * masqués (le code, le back-office et l'espace institutionnel restent en place pour la phase 2).
 */
final class Phase
{
    /** Parcours publics des actions (offre, transparence, souscription, paiement, onglets de Mon espace). */
    public static function actionsPubliques(): bool
    {
        return (bool) config('raaga.phase.actions_publiques');
    }
}
