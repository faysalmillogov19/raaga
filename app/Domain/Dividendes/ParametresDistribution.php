<?php

namespace App\Domain\Dividendes;

use InvalidArgumentException;

/**
 * Paramètres d'une distribution, sous forme de nombres entiers (FCFA, points de base).
 */
final readonly class ParametresDistribution
{
    /**
     * @param  list<string>  $canauxAutorises
     * @param  array<string, array{fixe: int, pb: int}>  $fraisCanaux  frais d'un versement par canal : fixe FCFA + points de base
     */
    public function __construct(
        /** true : montant global divisé par le nombre d'actions ; false : dividende par action saisi. */
        public bool $modeMontantGlobal,
        public ?int $montantGlobal,
        public ?int $dividendeParAction,
        /** Retenue fiscale (IRVM) en points de base : 1250 = 12,5 %. */
        public int $tauxRetenuePb,
        /** true : frais déduits du versement ; false : supportés par Raaga. */
        public bool $fraisParActionnaire,
        public int $seuilMinimal,
        public string $canalDefaut,
        public array $canauxAutorises,
        public array $fraisCanaux = [],
    ) {
        if ($modeMontantGlobal && ($montantGlobal === null || $montantGlobal < 0)) {
            throw new InvalidArgumentException('Le montant global doit être un entier positif.');
        }

        if (! $modeMontantGlobal && ($dividendeParAction === null || $dividendeParAction < 0)) {
            throw new InvalidArgumentException('Le dividende par action doit être un entier positif.');
        }

        if ($tauxRetenuePb < 0 || $tauxRetenuePb > 10_000) {
            throw new InvalidArgumentException('Le taux de retenue doit être compris entre 0 et 100 %.');
        }

        if ($seuilMinimal < 0) {
            throw new InvalidArgumentException('Le seuil minimal ne peut pas être négatif.');
        }

        if (! in_array($canalDefaut, $canauxAutorises, true)) {
            throw new InvalidArgumentException('Le canal par défaut doit faire partie des canaux autorisés.');
        }
    }
}
