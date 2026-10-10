<?php

namespace App\Domain\Dividendes;

use InvalidArgumentException;

/**
 * Actions détenues par un actionnaire à la date d'arrêté des droits.
 */
final readonly class Detention
{
    public function __construct(
        public int|string $userId,
        public int $nbActions,
        /** Succession en cours ou litige : dividende consigné. */
        public bool $bloquee = false,
        /** Canal choisi par l'actionnaire (null = canal par défaut). */
        public ?string $canal = null,
    ) {
        if ($nbActions < 1) {
            throw new InvalidArgumentException('Une détention porte sur au moins une action.');
        }
    }
}
