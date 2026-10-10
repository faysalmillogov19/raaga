<?php

namespace App\Services\Publique;

/**
 * Statistiques publiques agrégées de la collecte (page Transparence). Aucune donnée personnelle.
 */
final readonly class StatistiquesTransparence
{
    /**
     * @param  array<string, int>  $montantParCanal  Code CanalPaiement => montant encaissé (FCFA).
     * @param  array<string, int>  $souscripteursParTrancheMontant  Libellé de tranche de montant => nombre de souscripteurs.
     */
    public function __construct(
        public int $montantMedian,
        public array $montantParCanal,
        public array $souscripteursParTrancheMontant,
    ) {}
}
