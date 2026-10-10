<?php

namespace App\Domain\Allocation;

final readonly class StatistiquesAllocation
{
    /** Bornes hautes (incluses) des tranches de montant demandé, en FCFA. */
    public const TRANCHES_MONTANT = [
        '≤ 100 000' => 100_000,
        '100 000 – 1 000 000' => 1_000_000,
        '1 000 000 – 10 000 000' => 10_000_000,
        '> 10 000 000' => PHP_INT_MAX,
    ];

    /**
     * @param  array<string, array{nbPersonnes: int, actionsAllouees: int, montantARembourser: int}>  $repartitionParMontant
     */
    public function __construct(
        public int $capacite,
        public bool $sursouscrite,
        public int $nbPersonnes,
        public int $totalActionsDemandees,
        public int $totalActionsRetenues,
        public int $totalActionsAllouees,
        public int $montantTotalPaye,
        public int $montantTotalAlloue,
        public int $montantTotalARembourser,
        public int $nbServisIntegralement,
        public int $nbServisPartiellement,
        public int $nbNonServis,
        public array $repartitionParMontant,
        /** Part (0–100) des actions allouées détenue par les 10 plus gros souscripteurs. */
        public float $partDixPlusGros,
    ) {}

    /**
     * @param  list<AllocationIndividuelle>  $allocations
     */
    public static function calculer(array $allocations, int $capacite, int $valeurUnitaire): self
    {
        $repartition = array_fill_keys(
            array_keys(self::TRANCHES_MONTANT),
            ['nbPersonnes' => 0, 'actionsAllouees' => 0, 'montantARembourser' => 0],
        );

        $totaux = ['demandees' => 0, 'retenues' => 0, 'allouees' => 0, 'paye' => 0, 'rembourse' => 0];
        $integral = $partiel = $nonServi = 0;
        $allouees = [];

        foreach ($allocations as $a) {
            $totaux['demandees'] += $a->nbActionsDemandees;
            $totaux['retenues'] += $a->nbActionsRetenues;
            $totaux['allouees'] += $a->nbActionsAllouees;
            $totaux['paye'] += $a->montantPaye;
            $totaux['rembourse'] += $a->montantARembourser;
            $allouees[] = $a->nbActionsAllouees;

            match (true) {
                $a->estServieIntegralement() => $integral++,
                $a->estServiePartiellement() => $partiel++,
                default => $nonServi++,
            };

            foreach (self::TRANCHES_MONTANT as $libelle => $borne) {
                if ($a->montantPaye <= $borne) {
                    $repartition[$libelle]['nbPersonnes']++;
                    $repartition[$libelle]['actionsAllouees'] += $a->nbActionsAllouees;
                    $repartition[$libelle]['montantARembourser'] += $a->montantARembourser;
                    break;
                }
            }
        }

        rsort($allouees);
        $dixPlusGros = array_sum(array_slice($allouees, 0, 10));

        return new self(
            capacite: $capacite,
            sursouscrite: $totaux['retenues'] > $capacite,
            nbPersonnes: count($allocations),
            totalActionsDemandees: $totaux['demandees'],
            totalActionsRetenues: $totaux['retenues'],
            totalActionsAllouees: $totaux['allouees'],
            montantTotalPaye: $totaux['paye'],
            montantTotalAlloue: $totaux['allouees'] * $valeurUnitaire,
            montantTotalARembourser: $totaux['rembourse'],
            nbServisIntegralement: $integral,
            nbServisPartiellement: $partiel,
            nbNonServis: $nonServi,
            repartitionParMontant: $repartition,
            partDixPlusGros: $totaux['allouees'] > 0 ? round($dixPlusGros * 100 / $totaux['allouees'], 2) : 0.0,
        );
    }
}
