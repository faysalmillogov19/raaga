<?php

namespace App\Domain\Allocation;

/**
 * Résultat de l'allocation pour une personne (toutes ses demandes agrégées).
 */
final readonly class AllocationIndividuelle
{
    public function __construct(
        public int|string $userId,
        /** Total des actions payées. */
        public int $nbActionsDemandees,
        /** Actions prises en compte après plafonds (et règle des souscriptions multiples). */
        public int $nbActionsRetenues,
        public int $nbActionsAllouees,
        public int $montantPaye,
        public int $montantARembourser,
    ) {}

    public function estServieIntegralement(): bool
    {
        return $this->nbActionsAllouees === $this->nbActionsDemandees;
    }

    public function estServiePartiellement(): bool
    {
        return $this->nbActionsAllouees > 0 && $this->nbActionsAllouees < $this->nbActionsDemandees;
    }
}
