<?php

namespace App\Domain\Dividendes;

use LogicException;

/**
 * Calcul d'une distribution de dividendes. Fonctions pures, entiers FCFA, résultat déterministe.
 *
 * Pour chaque actionnaire :
 * - brut = actions × dividende par action ; retenue = brut × taux, arrondie à l'unité inférieure ;
 * - actions bloquées (succession, litige) → CONSIGNE : brut − retenue mis de côté, sans frais ;
 * - sinon le versement est calculé avec les frais de son canal ; s'il est inférieur au seuil
 *   minimal → REPORTE (brut − retenue cumulé au versement suivant, sans frais), sinon A_PAYER.
 *
 * Invariant vérifié à chaque calcul :
 *   Σ net à payer + Σ retenues + Σ frais déduits + Σ reportés + Σ consignés + reliquat = montant global
 * (les frais à la charge de Raaga sont hors du montant global).
 */
final class MoteurDistribution
{
    /**
     * @param  iterable<Detention>  $detentions
     */
    public function calculer(ParametresDistribution $p, iterable $detentions): ResultatDistribution
    {
        $detentions = is_array($detentions) ? $detentions : iterator_to_array($detentions);
        $nbActions = array_sum(array_map(fn (Detention $d) => $d->nbActions, $detentions));

        if ($p->modeMontantGlobal) {
            $montantGlobal = $p->montantGlobal;
            $dpa = $nbActions > 0 ? intdiv($montantGlobal, $nbActions) : 0;
        } else {
            $dpa = $p->dividendeParAction;
            $montantGlobal = $dpa * $nbActions;
        }

        $reliquat = $montantGlobal - $dpa * $nbActions;
        $lignes = [];
        $parCanal = [];

        foreach ($detentions as $d) {
            $brut = $d->nbActions * $dpa;
            $retenue = intdiv($brut * $p->tauxRetenuePb, 10_000);
            $apresRetenue = $brut - $retenue;
            $canal = $d->canal !== null && in_array($d->canal, $p->canauxAutorises, true) ? $d->canal : $p->canalDefaut;

            if ($d->bloquee) {
                $lignes[] = new LigneDistribution($d->userId, $d->nbActions, $brut, $retenue, 0, $apresRetenue, LigneDistribution::CONSIGNE, $canal);

                continue;
            }

            $frais = $this->frais($p, $canal, $apresRetenue);
            $verse = $p->fraisParActionnaire ? $apresRetenue - $frais : $apresRetenue;

            if ($verse < $p->seuilMinimal || $verse <= 0) {
                $lignes[] = new LigneDistribution($d->userId, $d->nbActions, $brut, $retenue, 0, $apresRetenue, LigneDistribution::REPORTE, $canal);

                continue;
            }

            $lignes[] = new LigneDistribution($d->userId, $d->nbActions, $brut, $retenue, $frais, $verse, LigneDistribution::A_PAYER, $canal);
            $parCanal[$canal] ??= ['nombre' => 0, 'montant' => 0];
            $parCanal[$canal]['nombre']++;
            $parCanal[$canal]['montant'] += $verse;
        }

        $somme = fn (string $statut, string $champ) => array_sum(array_map(
            fn (LigneDistribution $l) => $l->statut === $statut ? $l->{$champ} : 0,
            $lignes,
        ));
        $compte = fn (string $statut) => count(array_filter($lignes, fn (LigneDistribution $l) => $l->statut === $statut));

        ksort($parCanal);

        $resultat = new ResultatDistribution(
            lignes: $lignes,
            dividendeParAction: $dpa,
            montantGlobal: $montantGlobal,
            nbActions: $nbActions,
            nbBeneficiaires: count($lignes),
            totalBrut: array_sum(array_map(fn (LigneDistribution $l) => $l->brut, $lignes)),
            totalRetenue: array_sum(array_map(fn (LigneDistribution $l) => $l->retenue, $lignes)),
            totalNetAPayer: $somme(LigneDistribution::A_PAYER, 'net'),
            nbAPayer: $compte(LigneDistribution::A_PAYER),
            totalFrais: $somme(LigneDistribution::A_PAYER, 'frais'),
            fraisParActionnaire: $p->fraisParActionnaire,
            nbReportes: $compte(LigneDistribution::REPORTE),
            totalReporte: $somme(LigneDistribution::REPORTE, 'net'),
            nbConsignes: $compte(LigneDistribution::CONSIGNE),
            totalConsigne: $somme(LigneDistribution::CONSIGNE, 'net'),
            parCanal: $parCanal,
            reliquat: $reliquat,
        );

        $this->verifierInvariant($resultat);

        return $resultat;
    }

    private function frais(ParametresDistribution $p, string $canal, int $montant): int
    {
        $bareme = $p->fraisCanaux[$canal] ?? ['fixe' => 0, 'pb' => 0];

        return $bareme['fixe'] + intdiv($montant * $bareme['pb'], 10_000);
    }

    private function verifierInvariant(ResultatDistribution $r): void
    {
        $fraisDeduits = $r->fraisParActionnaire ? $r->totalFrais : 0;
        $total = $r->totalNetAPayer + $r->totalRetenue + $fraisDeduits + $r->totalReporte + $r->totalConsigne + $r->reliquat;

        if ($total !== $r->montantGlobal) {
            throw new LogicException("Distribution incohérente : {$total} ≠ {$r->montantGlobal}.");
        }

        foreach ($r->lignes as $l) {
            $deduits = $l->statut === LigneDistribution::A_PAYER && $r->fraisParActionnaire ? $l->frais : 0;

            if ($l->retenue + $deduits + $l->net !== $l->brut || $l->net < 0) {
                throw new LogicException("Ligne incohérente pour l'actionnaire {$l->userId}.");
            }
        }
    }
}
