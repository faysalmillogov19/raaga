<?php

namespace App\Domain\Allocation;

use App\Enums\MethodeAllocation;
use DateTimeZone;
use LogicException;
use OverflowException;

/**
 * Moteur d'allocation des actions en cas de sursouscription.
 *
 * Fonctions pures : aucun accès base, aucune horloge, résultat déterministe pour une entrée donnée.
 * Tous les calculs se font en nombres entiers d'actions.
 *
 * Étapes :
 *  1. Regroupement des demandes par personne (si souscriptions multiples interdites, seule la
 *     première demande payée est retenue, les suivantes sont intégralement remboursées).
 *  2. Plafonds individuels (plafond en actions, puis plafond de concentration) : l'excédent est
 *     retiré des demandes les plus récentes et remboursé.
 *  3. Si la demande retenue tient dans la capacité, tout le monde est servi ; sinon la méthode
 *     d'allocation de la tranche s'applique.
 */
final class MoteurAllocation
{
    /**
     * @param  iterable<Demande>  $demandes
     */
    public function allouer(ParametresAllocation $parametres, iterable $demandes): ResultatAllocation
    {
        $personnes = $this->regrouperParPersonne($demandes, $parametres->souscriptionsMultiples);
        $this->appliquerPlafond($personnes, $parametres->plafondIndividuel());

        $capacite = $parametres->capacite();
        $retenues = array_map(fn (array $p) => $p['retenu'], $personnes);

        $allouees = array_sum($retenues) <= $capacite
            ? $retenues
            : match ($parametres->methode) {
                MethodeAllocation::PremierArrive => $this->premierArrive($personnes, $capacite),
                MethodeAllocation::Prorata => $this->prorata($retenues, $capacite),
                MethodeAllocation::PrioritePetits => $this->prioritePetits($retenues, $capacite, $parametres->seuilGarantiPetits),
            };

        $allocations = [];

        foreach ($personnes as $cle => $personne) {
            $alloue = $allouees[$cle] ?? 0;

            $allocations[] = new AllocationIndividuelle(
                userId: $personne['userId'],
                nbActionsDemandees: $personne['demande'],
                nbActionsRetenues: $personne['retenu'],
                nbActionsAllouees: $alloue,
                montantPaye: $personne['demande'] * $parametres->valeurUnitaire,
                montantARembourser: ($personne['demande'] - $alloue) * $parametres->valeurUnitaire,
            );
        }

        $this->verifierInvariants($allocations, $capacite, $parametres->valeurUnitaire);

        return new ResultatAllocation(
            $allocations,
            StatistiquesAllocation::calculer($allocations, $capacite, $parametres->valeurUnitaire),
        );
    }

    /**
     * Regroupe les demandes par personne, triées par ordre de premier paiement
     * (égalité : ordre d'arrivée dans la liste). Cet ordre sert de départage partout.
     *
     * @param  iterable<Demande>  $demandes
     * @return array<array-key, array{userId: int|string, demande: int, retenu: int, ts: string, i: int, fragments: list<array{nb: int, retenu: int, ts: string, i: int}>}>
     */
    private function regrouperParPersonne(iterable $demandes, bool $souscriptionsMultiples): array
    {
        $utc = new DateTimeZone('UTC');
        $personnes = [];
        $i = 0;

        foreach ($demandes as $demande) {
            $cle = (string) $demande->userId;

            $personnes[$cle] ??= ['userId' => $demande->userId, 'fragments' => []];
            $personnes[$cle]['fragments'][] = [
                'nb' => $demande->nbActionsDemandees,
                'retenu' => $demande->nbActionsDemandees,
                'ts' => $demande->horodatagePaiement->setTimezone($utc)->format('Y-m-d\TH:i:s.u'),
                'i' => $i++,
            ];
        }

        foreach ($personnes as &$personne) {
            usort($personne['fragments'], fn (array $a, array $b) => [$a['ts'], $a['i']] <=> [$b['ts'], $b['i']]);

            if (! $souscriptionsMultiples) {
                foreach (array_slice(array_keys($personne['fragments']), 1) as $k) {
                    $personne['fragments'][$k]['retenu'] = 0;
                }
            }

            $personne['demande'] = array_sum(array_column($personne['fragments'], 'nb'));
            $personne['retenu'] = array_sum(array_column($personne['fragments'], 'retenu'));
            $personne['ts'] = $personne['fragments'][0]['ts'];
            $personne['i'] = $personne['fragments'][0]['i'];
        }
        unset($personne);

        uasort($personnes, fn (array $a, array $b) => [$a['ts'], $a['i']] <=> [$b['ts'], $b['i']]);

        return $personnes;
    }

    /**
     * Ramène chaque personne au plafond individuel ; l'excédent est retiré des demandes les plus récentes.
     *
     * @param  array<array-key, array{retenu: int, fragments: list<array{retenu: int}>}>  $personnes
     */
    private function appliquerPlafond(array &$personnes, ?int $plafond): void
    {
        if ($plafond === null) {
            return;
        }

        foreach ($personnes as &$personne) {
            $excedent = $personne['retenu'] - $plafond;

            for ($k = count($personne['fragments']) - 1; $k >= 0 && $excedent > 0; $k--) {
                $retrait = min($excedent, $personne['fragments'][$k]['retenu']);
                $personne['fragments'][$k]['retenu'] -= $retrait;
                $excedent -= $retrait;
            }

            $personne['retenu'] = min($personne['retenu'], $plafond);
        }
        unset($personne);
    }

    /**
     * Sert chaque demande dans l'ordre des paiements ; la dernière servie peut l'être partiellement.
     * Une demande complémentaire payée plus tard prend son rang à sa propre date de paiement.
     *
     * @param  array<array-key, array{fragments: list<array{retenu: int, ts: string, i: int}>}>  $personnes
     * @return array<array-key, int>
     */
    private function premierArrive(array $personnes, int $capacite): array
    {
        $file = [];
        $allouees = [];

        foreach ($personnes as $cle => $personne) {
            $allouees[$cle] = 0;

            foreach ($personne['fragments'] as $fragment) {
                if ($fragment['retenu'] > 0) {
                    $file[] = $fragment + ['cle' => $cle];
                }
            }
        }

        usort($file, fn (array $a, array $b) => [$a['ts'], $a['i']] <=> [$b['ts'], $b['i']]);

        $reste = $capacite;

        foreach ($file as $fragment) {
            if ($reste === 0) {
                break;
            }

            $servi = min($fragment['retenu'], $reste);
            $allouees[$fragment['cle']] += $servi;
            $reste -= $servi;
        }

        return $allouees;
    }

    /**
     * floor(demande × capacité / demandeTotale) pour chacun, puis les actions restantes une par une
     * selon le plus fort reste ; à reste égal, la personne ayant payé le plus tôt (ordre des clés).
     *
     * @param  array<array-key, int>  $demandes  Clés dans l'ordre de priorité (premier paiement).
     * @return array<array-key, int>
     */
    private function prorata(array $demandes, int $capacite): array
    {
        $total = array_sum($demandes);

        if ($total <= $capacite) {
            return $demandes;
        }

        $allouees = [];
        $restes = [];

        foreach ($demandes as $cle => $demande) {
            $produit = $demande * $capacite;

            if (! is_int($produit)) {
                throw new OverflowException('Dépassement de capacité entière dans le calcul au prorata.');
            }

            $allouees[$cle] = intdiv($produit, $total);
            $restes[$cle] = $produit % $total;
        }

        $aDistribuer = $capacite - array_sum($allouees);

        if ($aDistribuer > 0) {
            $rang = array_flip(array_keys($demandes));
            $candidats = array_keys(array_filter($restes, fn (int $reste) => $reste > 0));

            usort($candidats, fn ($a, $b) => [$restes[$b], $rang[$a]] <=> [$restes[$a], $rang[$b]]);

            foreach (array_slice($candidats, 0, $aDistribuer) as $cle) {
                $allouees[$cle]++;
            }
        }

        return $allouees;
    }

    /**
     * 1. Chacun reçoit d'abord min(demande, seuil garanti).
     * 2. Si la capacité ne suffit pas pour ces montants garantis, prorata sur ceux-ci.
     * 3. Sinon, le reliquat de capacité est réparti au prorata des demandes résiduelles.
     *
     * @param  array<array-key, int>  $demandes
     * @return array<array-key, int>
     */
    private function prioritePetits(array $demandes, int $capacite, int $seuil): array
    {
        $garantis = array_map(fn (int $demande) => min($demande, $seuil), $demandes);
        $totalGaranti = array_sum($garantis);

        if ($totalGaranti >= $capacite) {
            return $this->prorata($garantis, $capacite);
        }

        $residuels = [];

        foreach ($demandes as $cle => $demande) {
            $residuels[$cle] = $demande - $garantis[$cle];
        }

        $complements = $this->prorata($residuels, $capacite - $totalGaranti);

        foreach ($garantis as $cle => $garanti) {
            $garantis[$cle] = $garanti + $complements[$cle];
        }

        return $garantis;
    }

    /**
     * Garde-fou : un résultat qui viole un invariant ne doit jamais sortir du moteur.
     *
     * @param  list<AllocationIndividuelle>  $allocations
     */
    private function verifierInvariants(array $allocations, int $capacite, int $valeurUnitaire): void
    {
        $total = 0;

        foreach ($allocations as $a) {
            if ($a->nbActionsAllouees < 0 || $a->nbActionsAllouees > $a->nbActionsRetenues) {
                throw new LogicException("Allocation hors bornes pour l'utilisateur {$a->userId}.");
            }

            if ($a->nbActionsAllouees * $valeurUnitaire + $a->montantARembourser !== $a->montantPaye) {
                throw new LogicException("Montants incohérents pour l'utilisateur {$a->userId}.");
            }

            $total += $a->nbActionsAllouees;
        }

        if ($total > $capacite) {
            throw new LogicException("Allocation totale ({$total}) supérieure à la capacité ({$capacite}).");
        }
    }
}
