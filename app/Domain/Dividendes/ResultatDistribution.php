<?php

namespace App\Domain\Dividendes;

final readonly class ResultatDistribution
{
    /**
     * @param  list<LigneDistribution>  $lignes
     * @param  array<string, array{nombre: int, montant: int}>  $parCanal  versements à payer par canal (montant net)
     */
    public function __construct(
        public array $lignes,
        public int $dividendeParAction,
        public int $montantGlobal,
        public int $nbActions,
        public int $nbBeneficiaires,
        public int $totalBrut,
        public int $totalRetenue,
        public int $totalNetAPayer,
        public int $nbAPayer,
        /** Frais de tous les versements à payer, quel que soit le payeur. */
        public int $totalFrais,
        public bool $fraisParActionnaire,
        public int $nbReportes,
        public int $totalReporte,
        public int $nbConsignes,
        public int $totalConsigne,
        public array $parCanal,
        /** Part du montant global non distribuable à cause de l'arrondi du dividende par action. */
        public int $reliquat,
    ) {}

    /**
     * Frais à la charge de Raaga (hors montant global) : 0 si l'actionnaire les supporte.
     */
    public function fraisPourRaaga(): int
    {
        return $this->fraisParActionnaire ? 0 : $this->totalFrais;
    }
}
