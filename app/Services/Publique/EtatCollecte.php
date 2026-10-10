<?php

namespace App\Services\Publique;

use Carbon\CarbonImmutable;

/**
 * Chiffres publics agrégés d'une tranche ouverte (aucune donnée personnelle).
 */
final readonly class EtatCollecte
{
    public function __construct(
        public string $nomTranche,
        public int $valeurUnitaire,
        public ?int $plafondActionsParPersonne,
        public int $objectifMontant,
        public CarbonImmutable $dateOuverture,
        public CarbonImmutable $dateCloture,
        public int $montantSouscrit,
        public int $nbSouscripteurs,
        public int $nbActions,
        public bool $demo = false,
    ) {}

    /**
     * Part de l'objectif atteinte, en % entier (peut dépasser 100 en cas de sursouscription).
     */
    public function pourcentage(): int
    {
        return $this->objectifMontant > 0 ? intdiv($this->montantSouscrit * 100, $this->objectifMontant) : 0;
    }

    /**
     * Montant moyen par souscripteur (FCFA, arrondi à l'entier inférieur).
     */
    public function montantMoyen(): int
    {
        return $this->nbSouscripteurs > 0 ? intdiv($this->montantSouscrit, $this->nbSouscripteurs) : 0;
    }

    public function joursRestants(?CarbonImmutable $maintenant = null): int
    {
        $maintenant ??= CarbonImmutable::now();

        return $maintenant->greaterThanOrEqualTo($this->dateCloture)
            ? 0
            : (int) ceil($maintenant->diffInSeconds($this->dateCloture) / 86_400);
    }
}
