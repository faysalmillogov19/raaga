<?php

namespace Tests\Unit\Allocation;

use App\Domain\Allocation\Demande;
use App\Domain\Allocation\MoteurAllocation;
use App\Domain\Allocation\ParametresAllocation;
use App\Domain\Allocation\ResultatAllocation;
use App\Enums\MethodeAllocation;
use App\Enums\RegleArret;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class MoteurAllocationTest extends TestCase
{
    private const VALEUR = 10_000;

    public static function methodes(): array
    {
        return [
            'premier arrivé' => [MethodeAllocation::PremierArrive],
            'prorata' => [MethodeAllocation::Prorata],
            'priorité petits' => [MethodeAllocation::PrioritePetits],
        ];
    }

    // ---------------------------------------------------------------- Cas communs aux 3 méthodes

    #[DataProvider('methodes')]
    public function test_demande_inferieure_a_la_capacite_tout_le_monde_est_servi(MethodeAllocation $methode): void
    {
        $resultat = $this->allouer($this->parametres($methode, 100), [
            $this->demande('A', 10, 1),
            $this->demande('B', 20, 2),
            $this->demande('C', 30, 3),
        ]);

        $this->assertSame(['A' => 10, 'B' => 20, 'C' => 30], $this->allouees($resultat));
        $this->assertSame(0, $resultat->statistiques->montantTotalARembourser);
        $this->assertFalse($resultat->statistiques->sursouscrite);
        $this->assertSame(3, $resultat->statistiques->nbServisIntegralement);
    }

    #[DataProvider('methodes')]
    public function test_capacite_nulle_tout_est_rembourse(MethodeAllocation $methode): void
    {
        $parametres = new ParametresAllocation(
            valeurUnitaire: self::VALEUR,
            objectifMontant: 5_000, // inférieur à une action
            methode: $methode,
            seuilGarantiPetits: 100,
        );

        $resultat = $this->allouer($parametres, [$this->demande('A', 3, 1), $this->demande('B', 7, 2)]);

        $this->assertSame(0, $parametres->capacite());
        $this->assertSame(['A' => 0, 'B' => 0], $this->allouees($resultat));
        $this->assertSame(10 * self::VALEUR, $resultat->statistiques->montantTotalARembourser);
        $this->assertSame(2, $resultat->statistiques->nbNonServis);
    }

    #[DataProvider('methodes')]
    public function test_une_seule_personne_demande_plus_que_la_capacite(MethodeAllocation $methode): void
    {
        $resultat = $this->allouer($this->parametres($methode, 100), [$this->demande('A', 250, 1)]);

        $a = $resultat->pour('A');
        $this->assertSame(100, $a->nbActionsAllouees);
        $this->assertSame(150 * self::VALEUR, $a->montantARembourser);
        $this->assertTrue($a->estServiePartiellement());
    }

    #[DataProvider('methodes')]
    public function test_aucune_demande(MethodeAllocation $methode): void
    {
        $resultat = $this->allouer($this->parametres($methode, 100), []);

        $this->assertSame([], $resultat->allocations);
        $this->assertSame(0, $resultat->statistiques->totalActionsAllouees);
    }

    // ---------------------------------------------------------------- PREMIER_ARRIVE

    public function test_premier_arrive_sert_dans_l_ordre_des_paiements(): void
    {
        $resultat = $this->allouer($this->parametres(MethodeAllocation::PremierArrive, 100), [
            $this->demande('A', 40, 2),
            $this->demande('B', 50, 1),
            $this->demande('C', 30, 3),
            $this->demande('D', 20, 4),
        ]);

        $this->assertSame(['B' => 50, 'A' => 40, 'C' => 10, 'D' => 0], $this->allouees($resultat));
        $this->assertSame(20 * self::VALEUR, $resultat->pour('C')->montantARembourser);
        $this->assertSame(1, $resultat->statistiques->nbServisPartiellement);
        $this->assertSame(1, $resultat->statistiques->nbNonServis);
    }

    public function test_premier_arrive_egalite_d_horodatage_departagee_par_ordre_de_reception(): void
    {
        $resultat = $this->allouer($this->parametres(MethodeAllocation::PremierArrive, 100), [
            $this->demande('A', 60, 1),
            $this->demande('B', 60, 1),
        ]);

        $this->assertSame(['A' => 60, 'B' => 40], $this->allouees($resultat));
    }

    public function test_premier_arrive_un_complement_paye_plus_tard_prend_son_propre_rang(): void
    {
        $resultat = $this->allouer($this->parametres(MethodeAllocation::PremierArrive, 100), [
            $this->demande('A', 10, 1),
            $this->demande('B', 80, 2),
            $this->demande('A', 50, 3),
        ]);

        $this->assertSame(['A' => 20, 'B' => 80], $this->allouees($resultat));
        $this->assertSame(60, $resultat->pour('A')->nbActionsDemandees);
    }

    // ---------------------------------------------------------------- PRORATA

    public function test_prorata_distribue_le_reliquat_au_plus_fort_reste(): void
    {
        // 6×7/10 = 4,2 et 4×7/10 = 2,8 → base 4 + 2, la 7e action va au plus fort reste (B).
        $resultat = $this->allouer($this->parametres(MethodeAllocation::Prorata, 7), [
            $this->demande('A', 6, 1),
            $this->demande('B', 4, 2),
        ]);

        $this->assertSame(['A' => 4, 'B' => 3], $this->allouees($resultat));
    }

    public function test_prorata_a_reste_egal_le_paiement_le_plus_ancien_l_emporte(): void
    {
        // 5×10/15 = 3,33 pour chacun → base 3, une action restante pour le premier payé (B).
        $resultat = $this->allouer($this->parametres(MethodeAllocation::Prorata, 10), [
            $this->demande('A', 5, 2),
            $this->demande('B', 5, 1),
            $this->demande('C', 5, 3),
        ]);

        $this->assertSame(['B' => 4, 'A' => 3, 'C' => 3], $this->allouees($resultat));
    }

    public function test_prorata_proportions_exactes(): void
    {
        $resultat = $this->allouer($this->parametres(MethodeAllocation::Prorata, 100), [
            $this->demande('A', 100, 1),
            $this->demande('B', 300, 2),
        ]);

        $this->assertSame(['A' => 25, 'B' => 75], $this->allouees($resultat));
    }

    // ---------------------------------------------------------------- PRIORITE_PETITS

    public function test_priorite_petits_garantit_le_seuil_puis_prorata_sur_le_residuel(): void
    {
        // Garantis : 50 + 100 + 100 = 250, reliquat 50 réparti sur les résiduels 0 / 50 / 300.
        $resultat = $this->allouer($this->parametres(MethodeAllocation::PrioritePetits, 300, ['seuilGarantiPetits' => 100]), [
            $this->demande('A', 50, 1),
            $this->demande('B', 150, 2),
            $this->demande('C', 400, 3),
        ]);

        $this->assertSame(['A' => 50, 'B' => 107, 'C' => 143], $this->allouees($resultat));
    }

    public function test_priorite_petits_capacite_insuffisante_pour_les_garantis(): void
    {
        // Garantis : 3 × 100 = 300 > 150 → prorata sur les garantis.
        $resultat = $this->allouer($this->parametres(MethodeAllocation::PrioritePetits, 150, ['seuilGarantiPetits' => 100]), [
            $this->demande('A', 100, 1),
            $this->demande('B', 200, 2),
            $this->demande('C', 300, 3),
        ]);

        $this->assertSame(['A' => 50, 'B' => 50, 'C' => 50], $this->allouees($resultat));
    }

    public function test_priorite_petits_capacite_egale_aux_garantis(): void
    {
        $resultat = $this->allouer($this->parametres(MethodeAllocation::PrioritePetits, 200, ['seuilGarantiPetits' => 100]), [
            $this->demande('A', 100, 1),
            $this->demande('B', 300, 2),
        ]);

        $this->assertSame(['A' => 100, 'B' => 100], $this->allouees($resultat));
    }

    // ---------------------------------------------------------------- Plafonds

    public function test_plafond_par_personne_s_applique_meme_sans_sursouscription(): void
    {
        $resultat = $this->allouer($this->parametres(MethodeAllocation::Prorata, 1000, ['plafondActionsParPersonne' => 10]), [
            $this->demande('A', 25, 1),
        ]);

        $a = $resultat->pour('A');
        $this->assertSame(10, $a->nbActionsRetenues);
        $this->assertSame(10, $a->nbActionsAllouees);
        $this->assertSame(15 * self::VALEUR, $a->montantARembourser);
    }

    public function test_plafond_de_concentration_actif(): void
    {
        // 10 % de 100 actions = 10 actions maximum par personne.
        $resultat = $this->allouer($this->parametres(MethodeAllocation::Prorata, 100, ['plafondConcentrationPct' => 10]), [
            $this->demande('A', 30, 1),
            $this->demande('B', 5, 2),
        ]);

        $this->assertSame(['A' => 10, 'B' => 5], $this->allouees($resultat));
        $this->assertSame(20 * self::VALEUR, $resultat->pour('A')->montantARembourser);
    }

    public function test_le_plafond_le_plus_strict_l_emporte(): void
    {
        $parametres = $this->parametres(MethodeAllocation::Prorata, 100, [
            'plafondActionsParPersonne' => 8,
            'plafondConcentrationPct' => 10,
        ]);

        $this->assertSame(8, $parametres->plafondIndividuel());
        $this->assertSame(8, $this->allouer($parametres, [$this->demande('A', 30, 1)])->pour('A')->nbActionsAllouees);
    }

    public function test_l_excedent_au_plafond_est_retire_des_demandes_les_plus_recentes(): void
    {
        // A : 8 (t1) + 8 (t3), plafond 10 → retenu 8 (t1) + 2 (t3). En premier arrivé (capacité 15),
        // A est servi de 8, B de 7, et les 2 actions de t3 arrivent trop tard.
        // (Si l'excédent était retiré des plus anciennes, on obtiendrait A 5 / B 10.)
        $resultat = $this->allouer($this->parametres(MethodeAllocation::PremierArrive, 15, ['plafondActionsParPersonne' => 10]), [
            $this->demande('A', 8, 1),
            $this->demande('B', 10, 2),
            $this->demande('A', 8, 3),
        ]);

        $this->assertSame(['A' => 8, 'B' => 7], $this->allouees($resultat));
        $this->assertSame(10, $resultat->pour('A')->nbActionsRetenues);
    }

    // ---------------------------------------------------------------- Souscriptions multiples

    public function test_souscriptions_multiples_sont_agregees(): void
    {
        $resultat = $this->allouer($this->parametres(MethodeAllocation::Prorata, 100), [
            $this->demande('A', 3, 1),
            $this->demande('B', 2, 2),
            $this->demande('A', 4, 3),
        ]);

        $this->assertCount(2, $resultat->allocations);
        $this->assertSame(7, $resultat->pour('A')->nbActionsAllouees);
        $this->assertSame(7 * self::VALEUR, $resultat->pour('A')->montantPaye);
    }

    public function test_souscriptions_multiples_interdites_seule_la_premiere_compte(): void
    {
        $resultat = $this->allouer($this->parametres(MethodeAllocation::Prorata, 100, ['souscriptionsMultiples' => false]), [
            $this->demande('A', 4, 3),
            $this->demande('A', 3, 1),
        ]);

        $a = $resultat->pour('A');
        $this->assertSame(7, $a->nbActionsDemandees);
        $this->assertSame(3, $a->nbActionsRetenues);
        $this->assertSame(3, $a->nbActionsAllouees);
        $this->assertSame(4 * self::VALEUR, $a->montantARembourser);
    }

    // ---------------------------------------------------------------- Capacité

    public function test_capacite_avec_depassement_autorise(): void
    {
        $avecDepassement = $this->parametres(MethodeAllocation::Prorata, 100, [
            'regleArret' => RegleArret::DepassementAutorise,
            'pourcentageDepassementMax' => 20,
        ]);
        $sansDepassement = $this->parametres(MethodeAllocation::Prorata, 100, [
            'regleArret' => RegleArret::ArretAObjectif,
            'pourcentageDepassementMax' => 20,
        ]);

        $this->assertSame(120, $avecDepassement->capacite());
        $this->assertSame(100, $sansDepassement->capacite());
    }

    public function test_depassement_autorise_sans_pourcentage_sert_toutes_les_demandes(): void
    {
        $parametres = $this->parametres(MethodeAllocation::Prorata, 100, [
            'regleArret' => RegleArret::DepassementAutorise,
            'pourcentageDepassementMax' => null,
        ]);

        $resultat = $this->allouer($parametres, [$this->demande('a', 150, 1), $this->demande('b', 90, 2)]);

        $this->assertTrue($parametres->depassementSansLimite());
        $this->assertSame(['a' => 150, 'b' => 90], $this->allouees($resultat));
        $this->assertSame(0, array_sum(array_map(fn ($a) => $a->montantARembourser, $resultat->allocations)));

        // En « Arrêt à l'objectif », un pourcentage vide ne change rien : capacité de l'objectif.
        $arret = $this->parametres(MethodeAllocation::Prorata, 100, ['pourcentageDepassementMax' => null]);
        $this->assertFalse($arret->depassementSansLimite());
        $this->assertSame(100, array_sum($this->allouees($this->allouer($arret, [$this->demande('a', 150, 1), $this->demande('b', 90, 2)]))));
    }

    public function test_capacite_arrondie_a_l_action_inferieure(): void
    {
        $parametres = new ParametresAllocation(self::VALEUR, 1_005_000, MethodeAllocation::Prorata);

        $this->assertSame(100, $parametres->capacite());
    }

    // ---------------------------------------------------------------- Statistiques

    public function test_statistiques_repartition_par_montant_et_concentration(): void
    {
        $resultat = $this->allouer($this->parametres(MethodeAllocation::PrioritePetits, 300, ['seuilGarantiPetits' => 100]), [
            $this->demande('A', 50, 1),   //   500 000 FCFA
            $this->demande('B', 150, 2),  // 1 500 000 FCFA
            $this->demande('C', 400, 3),  // 4 000 000 FCFA
        ]);

        $stats = $resultat->statistiques;
        $this->assertSame(1, $stats->nbServisIntegralement);
        $this->assertSame(2, $stats->nbServisPartiellement);
        $this->assertSame(300, $stats->totalActionsAllouees);
        $this->assertSame(300 * self::VALEUR, $stats->montantTotalARembourser);
        $this->assertSame(1, $stats->repartitionParMontant['100 000 – 1 000 000']['nbPersonnes']);
        $this->assertSame(2, $stats->repartitionParMontant['1 000 000 – 10 000 000']['nbPersonnes']);
        $this->assertSame(250, $stats->repartitionParMontant['1 000 000 – 10 000 000']['actionsAllouees']);
        $this->assertSame(100.0, $stats->partDixPlusGros);
    }

    // ---------------------------------------------------------------- Validation des entrées

    public function test_une_demande_doit_porter_sur_au_moins_une_action(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->demande('A', 0, 1);
    }

    public function test_priorite_petits_exige_un_seuil_garanti(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new ParametresAllocation(self::VALEUR, 1_000_000, MethodeAllocation::PrioritePetits, seuilGarantiPetits: 0);
    }

    public function test_plafond_de_concentration_borne_entre_1_et_100(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new ParametresAllocation(self::VALEUR, 1_000_000, MethodeAllocation::Prorata, plafondConcentrationPct: 0);
    }

    // ---------------------------------------------------------------- Test de propriété

    public static function jeuxAleatoires(): array
    {
        $jeux = [];

        foreach (self::methodes() as $nom => [$methode]) {
            foreach ([7, 42, 2026] as $graine) {
                $jeux["{$nom} / graine {$graine}"] = [$methode, $graine];
            }
        }

        return $jeux;
    }

    #[DataProvider('jeuxAleatoires')]
    public function test_invariants_sur_10_000_demandes_aleatoires(MethodeAllocation $methode, int $graine): void
    {
        mt_srand($graine);

        $debut = new DateTimeImmutable('2026-11-01 08:00:00');
        $demandes = [];
        $totalDemande = 0;

        for ($k = 0; $k < 10_000; $k++) {
            $tirage = mt_rand(1, 100);
            $nb = match (true) {
                $tirage <= 70 => mt_rand(1, 10),
                $tirage <= 90 => mt_rand(10, 500),
                $tirage <= 99 => mt_rand(500, 2_000),
                default => mt_rand(2_000, 5_000), // jusqu'à 50 000 000 FCFA
            };

            $totalDemande += $nb;
            $demandes[] = new Demande(
                userId: mt_rand(1, 6_000), // collisions volontaires → agrégation
                nbActionsDemandees: $nb,
                horodatagePaiement: $debut->modify('+'.mt_rand(0, 1_209_600).' seconds')->modify('+'.mt_rand(0, 999_999).' microseconds'),
            );
        }

        $parametres = new ParametresAllocation(
            valeurUnitaire: self::VALEUR,
            objectifMontant: intdiv($totalDemande * mt_rand(20, 120), 100) * self::VALEUR + mt_rand(0, self::VALEUR - 1),
            methode: $methode,
            regleArret: mt_rand(0, 1) ? RegleArret::DepassementAutorise : RegleArret::ArretAObjectif,
            pourcentageDepassementMax: mt_rand(0, 30),
            seuilGarantiPetits: mt_rand(1, 200),
            plafondActionsParPersonne: mt_rand(0, 1) ? mt_rand(500, 5_000) : null,
            plafondConcentrationPct: mt_rand(0, 1) ? mt_rand(1, 20) : null,
            souscriptionsMultiples: (bool) mt_rand(0, 3), // 1 fois sur 4 interdites
        );

        $resultat = (new MoteurAllocation)->allouer($parametres, $demandes);
        $capacite = $parametres->capacite();
        $plafond = $parametres->plafondIndividuel();

        $seuil = $parametres->seuilGarantiPetits;
        $garantiTotal = array_sum(array_map(fn ($a) => min($a->nbActionsRetenues, $seuil), $resultat->allocations));
        $verifierGaranti = $methode === MethodeAllocation::PrioritePetits && $garantiTotal <= $capacite;

        foreach ($resultat->allocations as $a) {
            $this->assertGreaterThanOrEqual(0, $a->nbActionsAllouees);
            $this->assertLessThanOrEqual($a->nbActionsRetenues, $a->nbActionsAllouees);
            $this->assertLessThanOrEqual($a->nbActionsDemandees, $a->nbActionsRetenues);
            $this->assertSame($a->montantPaye, $a->nbActionsAllouees * self::VALEUR + $a->montantARembourser);

            if ($plafond !== null) {
                $this->assertLessThanOrEqual($plafond, $a->nbActionsRetenues);
            }

            // Priorité petits : chacun reçoit au moins sa part garantie quand la capacité la couvre.
            if ($verifierGaranti) {
                $this->assertGreaterThanOrEqual(min($a->nbActionsRetenues, $seuil), $a->nbActionsAllouees);
            }
        }

        $stats = $resultat->statistiques;
        $this->assertLessThanOrEqual($capacite, $stats->totalActionsAllouees);
        // Aucune action perdue : on alloue tout ce qui peut l'être.
        $this->assertSame(min($capacite, $stats->totalActionsRetenues), $stats->totalActionsAllouees);
        $this->assertSame($totalDemande, $stats->totalActionsDemandees);
        $this->assertSame($totalDemande * self::VALEUR, $stats->montantTotalPaye);
        $this->assertSame($stats->montantTotalPaye, $stats->montantTotalAlloue + $stats->montantTotalARembourser);

        // Déterminisme : même entrée, même sortie.
        $this->assertEquals($resultat, (new MoteurAllocation)->allouer($parametres, $demandes));
    }

    // ---------------------------------------------------------------- Outils

    private function parametres(MethodeAllocation $methode, int $capaciteEnActions, array $options = []): ParametresAllocation
    {
        return new ParametresAllocation(...[
            'valeurUnitaire' => self::VALEUR,
            'objectifMontant' => $capaciteEnActions * self::VALEUR,
            'methode' => $methode,
            'seuilGarantiPetits' => 100,
            ...$options,
        ]);
    }

    private function demande(string $userId, int $nb, int $minute): Demande
    {
        return new Demande($userId, $nb, (new DateTimeImmutable('2026-11-01 08:00:00'))->modify("+{$minute} minutes"));
    }

    /**
     * @param  list<Demande>  $demandes
     */
    private function allouer(ParametresAllocation $parametres, array $demandes): ResultatAllocation
    {
        return (new MoteurAllocation)->allouer($parametres, $demandes);
    }

    /**
     * @return array<string, int>
     */
    private function allouees(ResultatAllocation $resultat): array
    {
        $allouees = [];

        foreach ($resultat->allocations as $a) {
            $allouees[$a->userId] = $a->nbActionsAllouees;
        }

        return $allouees;
    }
}
