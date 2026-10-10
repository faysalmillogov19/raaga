<?php

namespace App\Domain\Allocation;

final readonly class ResultatAllocation
{
    /**
     * @param  list<AllocationIndividuelle>  $allocations  Triées par ordre de premier paiement.
     */
    public function __construct(
        public array $allocations,
        public StatistiquesAllocation $statistiques,
    ) {}

    public function pour(int|string $userId): ?AllocationIndividuelle
    {
        foreach ($this->allocations as $allocation) {
            if ((string) $allocation->userId === (string) $userId) {
                return $allocation;
            }
        }

        return null;
    }
}
