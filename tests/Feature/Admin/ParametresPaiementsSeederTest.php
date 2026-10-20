<?php

namespace Tests\Feature\Admin;

use App\Enums\DecisionParametrage;
use App\Enums\StatutGrilleTarifaire;
use App\Models\ParametrageDecision;
use App\Models\ParametresPaiements;
use Database\Seeders\ParametresPaiementsSeeder;
use Database\Seeders\ReferentielsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Délais de paiement initiaux confirmés d'office par le seeder des référentiels (caution 72 h, complément 48 h,
 * avance 30 jours, correction d'une identité invalide 48 h) ; jamais rien d'écrasé.
 */
class ParametresPaiementsSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_valeurs_initiales_confirmees_d_office_et_idempotentes(): void
    {
        $this->seed(ReferentielsSeeder::class);

        $fiche = ParametresPaiements::query()->sole();
        $this->assertSame(StatutGrilleTarifaire::Confirme, $fiche->statut);
        $this->assertSame([48, 172_800], [$fiche->delai_kyc_valeur, $fiche->delai_kyc_secondes]);
        $decision = ParametrageDecision::query()->where('objet_type', 'delais')->sole();
        $this->assertSame([DecisionParametrage::Confirme, null, 1], [$decision->decision, $decision->decideur_id, $decision->version]);
        $this->assertSame(['caution' => 259_200, 'complement' => 172_800, 'avance' => 2_592_000, 'kyc' => 172_800], ParametresPaiements::confirmes());
        // Délai confirmé affiché dans sa plus grande unité exacte.
        $this->assertSame('2 jours', ParametresPaiements::libelleEnVigueur('kyc'));

        // Relancé : rien n'est créé ni modifié.
        $fiche->forceFill(['delai_kyc_valeur' => 24])->save();
        $this->seed(ParametresPaiementsSeeder::class);
        $this->assertSame(1, ParametresPaiements::query()->count());
        $this->assertSame(24, ParametresPaiements::query()->sole()->delai_kyc_valeur);
        $this->assertSame(1, ParametrageDecision::query()->where('objet_type', 'delais')->count());
    }
}
