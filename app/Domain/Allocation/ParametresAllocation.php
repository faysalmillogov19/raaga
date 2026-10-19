<?php

namespace App\Domain\Allocation;

use App\Enums\MethodeAllocation;
use App\Enums\RegleArret;
use InvalidArgumentException;

/**
 * Sous-ensemble des paramètres d'une tranche utile à l'allocation. Montants en FCFA entiers.
 */
final readonly class ParametresAllocation
{
    public function __construct(
        public int $valeurUnitaire,
        public int $objectifMontant,
        public MethodeAllocation $methode,
        public RegleArret $regleArret = RegleArret::ArretAObjectif,
        /** Dépassement autorisé au-delà de l'objectif, en % ; null avec « Dépassement autorisé » = sans limite. */
        public ?int $pourcentageDepassementMax = 0,
        public int $seuilGarantiPetits = 0,
        public ?int $plafondActionsParPersonne = null,
        public ?int $plafondConcentrationPct = null,
        public bool $souscriptionsMultiples = true,
    ) {
        if ($valeurUnitaire < 1) {
            throw new InvalidArgumentException('La valeur unitaire doit être un entier strictement positif.');
        }

        if ($objectifMontant < 0) {
            throw new InvalidArgumentException("L'objectif de collecte ne peut pas être négatif.");
        }

        if ($pourcentageDepassementMax !== null && $pourcentageDepassementMax < 0) {
            throw new InvalidArgumentException('Le pourcentage de dépassement ne peut pas être négatif.');
        }

        if ($seuilGarantiPetits < 0 || ($methode === MethodeAllocation::PrioritePetits && $seuilGarantiPetits < 1)) {
            throw new InvalidArgumentException('Le seuil garanti aux petits souscripteurs doit être au moins 1 action.');
        }

        if ($plafondActionsParPersonne !== null && $plafondActionsParPersonne < 1) {
            throw new InvalidArgumentException("Le plafond d'actions par personne doit être au moins 1.");
        }

        if ($plafondConcentrationPct !== null && ($plafondConcentrationPct < 1 || $plafondConcentrationPct > 100)) {
            throw new InvalidArgumentException('Le plafond de concentration doit être compris entre 1 et 100 %.');
        }
    }

    /** Dépassement autorisé sans pourcentage maximal : toutes les demandes retenues sont servies. */
    public function depassementSansLimite(): bool
    {
        return $this->regleArret === RegleArret::DepassementAutorise && $this->pourcentageDepassementMax === null;
    }

    /**
     * Nombre maximal d'actions à allouer : objectif (+ dépassement autorisé) / valeur unitaire, arrondi inférieur.
     * Dépassement sans limite : capacité de l'objectif seul (base du plafond de concentration), relevée par le moteur
     * au total des demandes retenues.
     */
    public function capacite(): int
    {
        $montantMax = $this->objectifMontant;

        if ($this->regleArret === RegleArret::DepassementAutorise && $this->pourcentageDepassementMax !== null) {
            $montantMax = intdiv($this->objectifMontant * (100 + $this->pourcentageDepassementMax), 100);
        }

        return intdiv($montantMax, $this->valeurUnitaire);
    }

    /**
     * Plafond effectif par personne : le plus strict entre le plafond en actions et le plafond
     * de concentration (en % de la capacité de la tranche). Null = aucun plafond.
     */
    public function plafondIndividuel(): ?int
    {
        $plafonds = [];

        if ($this->plafondActionsParPersonne !== null) {
            $plafonds[] = $this->plafondActionsParPersonne;
        }

        if ($this->plafondConcentrationPct !== null) {
            $plafonds[] = intdiv($this->capacite() * $this->plafondConcentrationPct, 100);
        }

        return $plafonds === [] ? null : min($plafonds);
    }
}
