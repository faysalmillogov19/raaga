<?php

namespace Tests\Unit\Dividendes;

use App\Domain\Dividendes\Detention;
use App\Domain\Dividendes\LigneDistribution;
use App\Domain\Dividendes\MoteurDistribution;
use App\Domain\Dividendes\ParametresDistribution;
use App\Domain\Dividendes\ResultatDistribution;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class MoteurDistributionTest extends TestCase
{
    private function parametres(array $options = []): ParametresDistribution
    {
        return new ParametresDistribution(...[
            'modeMontantGlobal' => true,
            'montantGlobal' => 1_000_000,
            'dividendeParAction' => null,
            'tauxRetenuePb' => 1250,
            'fraisParActionnaire' => true,
            'seuilMinimal' => 0,
            'canalDefaut' => 'ORANGE_MONEY',
            'canauxAutorises' => ['ORANGE_MONEY', 'MOOV_MONEY', 'VIREMENT'],
            'fraisCanaux' => [],
            ...$options,
        ]);
    }

    private function calculer(ParametresDistribution $p, array $detentions): ResultatDistribution
    {
        return (new MoteurDistribution)->calculer($p, $detentions);
    }

    /** L'égalité exigée par la spécification. */
    private function assertInvariant(ResultatDistribution $r): void
    {
        $frais = $r->fraisParActionnaire ? $r->totalFrais : 0;
        $this->assertSame(
            $r->montantGlobal,
            $r->totalNetAPayer + $r->totalRetenue + $frais + $r->totalReporte + $r->totalConsigne + $r->reliquat,
        );
    }

    public function test_montant_global_divise_par_le_nombre_d_actions_avec_reliquat(): void
    {
        // 1 000 000 / 3 actions = 333 333 par action, reliquat 1.
        $r = $this->calculer($this->parametres(['tauxRetenuePb' => 0]), [new Detention('A', 1), new Detention('B', 2)]);

        $this->assertSame(333_333, $r->dividendeParAction);
        $this->assertSame(1, $r->reliquat);
        $this->assertSame(999_999, $r->totalNetAPayer);
        $this->assertInvariant($r);
    }

    public function test_dividende_par_action_saisi_directement(): void
    {
        $r = $this->calculer($this->parametres(['modeMontantGlobal' => false, 'montantGlobal' => null, 'dividendeParAction' => 500, 'tauxRetenuePb' => 0]), [
            new Detention('A', 25),
            new Detention('B', 4),
        ]);

        $this->assertSame(14_500, $r->montantGlobal);
        $this->assertSame(0, $r->reliquat);
        $this->assertSame(12_500, $r->lignes[0]->brut);
        $this->assertInvariant($r);
    }

    public function test_retenue_fiscale_arrondie_a_l_unite_inferieure(): void
    {
        // Brut 12 345 × 12,5 % = 1 543,125 → 1 543.
        $r = $this->calculer($this->parametres(['modeMontantGlobal' => false, 'montantGlobal' => null, 'dividendeParAction' => 12_345]), [new Detention('A', 1)]);

        $this->assertSame(1_543, $r->lignes[0]->retenue);
        $this->assertSame(10_802, $r->lignes[0]->net);
        $this->assertInvariant($r);
    }

    public function test_frais_a_la_charge_de_l_actionnaire_ou_de_raaga(): void
    {
        $base = ['modeMontantGlobal' => false, 'montantGlobal' => null, 'dividendeParAction' => 10_000, 'tauxRetenuePb' => 0,
            'fraisCanaux' => ['ORANGE_MONEY' => ['fixe' => 100, 'pb' => 100]]];

        // Frais : 100 + 1 % de 10 000 = 200.
        $actionnaire = $this->calculer($this->parametres([...$base, 'fraisParActionnaire' => true]), [new Detention('A', 1)]);
        $this->assertSame(200, $actionnaire->lignes[0]->frais);
        $this->assertSame(9_800, $actionnaire->lignes[0]->net);
        $this->assertSame(0, $actionnaire->fraisPourRaaga());
        $this->assertInvariant($actionnaire);

        $raaga = $this->calculer($this->parametres([...$base, 'fraisParActionnaire' => false]), [new Detention('A', 1)]);
        $this->assertSame(10_000, $raaga->lignes[0]->net);
        $this->assertSame(200, $raaga->fraisPourRaaga());
        $this->assertInvariant($raaga);
    }

    public function test_un_versement_sous_le_seuil_est_reporte_sans_frais(): void
    {
        $r = $this->calculer($this->parametres(['modeMontantGlobal' => false, 'montantGlobal' => null, 'dividendeParAction' => 300,
            'tauxRetenuePb' => 0, 'seuilMinimal' => 1_000, 'fraisCanaux' => ['ORANGE_MONEY' => ['fixe' => 50, 'pb' => 0]]]), [
                new Detention('petit', 2),    // 600 < 1 000 → reporté
                new Detention('grand', 10),   // 3 000 − 50 = 2 950 → à payer
            ]);

        $this->assertSame(LigneDistribution::REPORTE, $r->lignes[0]->statut);
        $this->assertSame(600, $r->lignes[0]->net);
        $this->assertSame(0, $r->lignes[0]->frais);
        $this->assertSame(1, $r->nbReportes);
        $this->assertSame(600, $r->totalReporte);
        $this->assertSame(2_950, $r->totalNetAPayer);
        $this->assertInvariant($r);
    }

    public function test_les_actions_bloquees_sont_consignees(): void
    {
        $r = $this->calculer($this->parametres(['modeMontantGlobal' => false, 'montantGlobal' => null, 'dividendeParAction' => 1_000, 'tauxRetenuePb' => 1000]), [
            new Detention('succession', 5, bloquee: true),
            new Detention('libre', 5),
        ]);

        $this->assertSame(LigneDistribution::CONSIGNE, $r->lignes[0]->statut);
        $this->assertSame(4_500, $r->totalConsigne);
        $this->assertSame(1, $r->nbConsignes);
        $this->assertSame(1, $r->nbAPayer);
        $this->assertInvariant($r);
    }

    public function test_canal_de_l_actionnaire_ou_canal_par_defaut(): void
    {
        $r = $this->calculer($this->parametres(['tauxRetenuePb' => 0]), [
            new Detention('A', 1, canal: 'MOOV_MONEY'),
            new Detention('B', 1, canal: 'ESPECES'), // non autorisé → canal par défaut
            new Detention('C', 1),
        ]);

        $this->assertSame(['MOOV_MONEY' => 1, 'ORANGE_MONEY' => 2], array_map(fn ($c) => $c['nombre'], $r->parCanal));
        $this->assertInvariant($r);
    }

    public function test_aucun_actionnaire(): void
    {
        $r = $this->calculer($this->parametres(), []);

        $this->assertSame(0, $r->nbBeneficiaires);
        $this->assertSame(0, $r->dividendeParAction);
        $this->assertSame(1_000_000, $r->reliquat);
        $this->assertInvariant($r);
    }

    public function test_le_canal_par_defaut_doit_etre_autorise(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->parametres(['canalDefaut' => 'ESPECES']);
    }

    // ---------------------------------------------------------------- Propriété

    public static function graines(): array
    {
        return ['graine 7' => [7], 'graine 42' => [42], 'graine 2026' => [2026]];
    }

    #[DataProvider('graines')]
    public function test_l_egalite_tient_sur_5000_actionnaires_aleatoires(int $graine): void
    {
        mt_srand($graine);
        $detentions = [];
        for ($i = 0; $i < 5_000; $i++) {
            $detentions[] = new Detention($i, mt_rand(1, 100) <= 80 ? mt_rand(1, 20) : mt_rand(20, 5_000),
                bloquee: mt_rand(1, 100) <= 2,
                canal: ['ORANGE_MONEY', 'MOOV_MONEY', 'VIREMENT', 'ESPECES', null][mt_rand(0, 4)]);
        }

        $global = mt_rand(0, 1) === 1;
        $r = $this->calculer($this->parametres([
            'modeMontantGlobal' => $global,
            'montantGlobal' => $global ? mt_rand(10_000_000, 900_000_000) : null,
            'dividendeParAction' => $global ? null : mt_rand(1, 2_000),
            'tauxRetenuePb' => mt_rand(0, 2_500),
            'fraisParActionnaire' => (bool) mt_rand(0, 1),
            'seuilMinimal' => mt_rand(0, 5_000),
            'fraisCanaux' => ['ORANGE_MONEY' => ['fixe' => mt_rand(0, 300), 'pb' => mt_rand(0, 200)], 'MOOV_MONEY' => ['fixe' => mt_rand(0, 300), 'pb' => mt_rand(0, 200)]],
        ]), $detentions);

        $this->assertInvariant($r);
        $this->assertSame($r->nbBeneficiaires, $r->nbAPayer + $r->nbReportes + $r->nbConsignes);
        $this->assertSame($r->totalNetAPayer, array_sum(array_column($r->parCanal, 'montant')));
        // Le reliquat d'arrondi est toujours inférieur au nombre d'actions (moins d'un franc par action).
        $this->assertLessThan(max(1, $r->nbActions), $r->reliquat);
    }
}
