<?php

namespace App\Domain\Dividendes;

/**
 * Résultat pour un actionnaire. Toujours : brut = retenue + frais déduits + net.
 */
final readonly class LigneDistribution
{
    public const A_PAYER = 'A_PAYER';

    public const REPORTE = 'REPORTE';

    public const CONSIGNE = 'CONSIGNE';

    public function __construct(
        public int|string $userId,
        public int $nbActions,
        public int $brut,
        public int $retenue,
        /** Frais du versement (déduits du net seulement s'ils sont à la charge de l'actionnaire). */
        public int $frais,
        /** Montant versé (A_PAYER), reporté au versement suivant (REPORTE) ou consigné (CONSIGNE). */
        public int $net,
        public string $statut,
        public string $canal,
    ) {}
}
