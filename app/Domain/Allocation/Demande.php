<?php

namespace App\Domain\Allocation;

use DateTimeImmutable;
use InvalidArgumentException;

/**
 * Une demande payée, telle que vue par le moteur d'allocation.
 */
final readonly class Demande
{
    public function __construct(
        public int|string $userId,
        public int $nbActionsDemandees,
        public DateTimeImmutable $horodatagePaiement,
    ) {
        if ($nbActionsDemandees < 1) {
            throw new InvalidArgumentException("Le nombre d'actions demandées doit être au moins 1.");
        }
    }
}
