<?php

namespace Tests\Feature\Admin;

use App\Enums\StatutAvenant;
use App\Http\Controllers\Admin\TranchesController;
use App\Models\Avenant;
use App\Models\Duree;
use App\Models\Tranche;
use BackedEnum;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\ActeursParametrage;
use Tests\TestCase;

/**
 * Délai de paiement d'une tranche d'actions au format des autres délais : valeur + durée obligatoire, valeur en
 * secondes calculée ; conversion des tranches existantes, de leurs versions figées et des avenants.
 */
class DelaiTranchesTest extends TestCase
{
    use ActeursParametrage, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->creerActeurs();
    }

    /** Formulaire de tranche complet, avec le délai en valeur + code de durée. */
    private function formulaire(array $surcharges = []): array
    {
        $valeurs = [...TranchesController::valeursParDefaut(), 'nom' => 'Tranche 1 · 2027', 'valeur_unitaire' => 10_000, 'objectif_montant' => 1_000_000_000];
        foreach ($valeurs as $champ => $valeur) {
            $valeurs[$champ] = match (true) {
                $valeur instanceof BackedEnum => $valeur->value,
                $valeur instanceof \DateTimeInterface => $valeur->format('Y-m-d\TH:i'),
                is_bool($valeur) => $valeur ? '1' : '0',
                default => $valeur,
            };
        }
        unset($valeurs['delai_paiement_duree_id'], $valeurs['delai_paiement_secondes']);

        return [...$valeurs, 'delai_paiement_duree' => 'HEURE', ...$surcharges];
    }

    public function test_saisie_en_valeur_et_duree_avec_conversion_en_secondes(): void
    {
        // Valeur par défaut : 48 heures.
        $this->assertMatchesRegularExpression('/name="delai_paiement_valeur"[^>]*value="48"/', $this->en($this->regulateur)->get(route('admin.tranches.create'))->assertOk()->getContent());

        $this->en($this->regulateur)->post(route('admin.tranches.store'), $this->formulaire(['delai_paiement_valeur' => 2, 'delai_paiement_duree' => 'JOUR']))
            ->assertSessionHasNoErrors()->assertRedirect();
        $tranche = Tranche::query()->latest('id')->firstOrFail();
        $this->assertSame([2, Duree::parCode('JOUR')->id, 172_800], [$tranche->delai_paiement_valeur, $tranche->delai_paiement_duree_id, $tranche->delai_paiement_secondes]);
        $this->assertSame([172_800, '2 jours'], [$tranche->delaiPaiementSecondes(), $tranche->libelleDelaiPaiement()]);

        // Version figée à la soumission : valeur, durée et secondes.
        $this->en($this->regulateur)->post(route('admin.tranches.soumettre', $tranche))->assertRedirect();
        $parametres = $tranche->versions()->latest('numero')->value('parametres');
        $this->assertSame([2, Duree::parCode('JOUR')->id, 172_800], [$parametres['delai_paiement_valeur'], $parametres['delai_paiement_duree_id'], $parametres['delai_paiement_secondes']]);
        $this->assertArrayNotHasKey('delai_paiement_heures', $parametres);
        $this->en($this->validateur)->get(route('admin.tranches.show', $tranche))->assertOk()->assertSee('Jours')->assertSee("172\u{00A0}800 s");
    }

    public function test_valeur_et_duree_obligatoires(): void
    {
        $store = route('admin.tranches.store');
        $this->en($this->regulateur)->post($store, $this->formulaire(['delai_paiement_duree' => '']))->assertSessionHasErrors('delai_paiement_duree');
        $this->en($this->regulateur)->post($store, $this->formulaire(['delai_paiement_duree' => 'SEMAINE']))->assertSessionHasErrors('delai_paiement_duree');
        $this->en($this->regulateur)->post($store, $this->formulaire(['delai_paiement_valeur' => '']))->assertSessionHasErrors('delai_paiement_valeur');
        $this->en($this->regulateur)->post($store, $this->formulaire(['delai_paiement_valeur' => 400, 'delai_paiement_duree' => 'JOUR']))
            ->assertSessionHasErrors(['delai_paiement_valeur' => 'Un délai ne peut pas dépasser un an.']);
        $this->assertSame(0, Tranche::count());
    }

    public function test_conversion_des_tranches_versions_figees_et_avenants_existants(): void
    {
        // Données au nouveau format, ramenées à l'ancien (heures) par le retour arrière de la migration.
        $this->en($this->regulateur)->post(route('admin.tranches.store'), $this->formulaire(['delai_paiement_valeur' => 36]))->assertSessionHasNoErrors();
        $tranche = Tranche::query()->latest('id')->firstOrFail();
        $this->en($this->regulateur)->post(route('admin.tranches.soumettre', $tranche));
        $avenant = $tranche->avenants()->create(['ancienne_valeur' => ['delai_paiement_valeur' => 36, 'delai_paiement_duree_id' => Duree::parCode('HEURE')->id, 'delai_paiement_secondes' => 129_600],
            'nouvelle_valeur' => ['delai_paiement_valeur' => 24, 'delai_paiement_duree_id' => Duree::parCode('HEURE')->id, 'delai_paiement_secondes' => 86_400],
            'motif' => 'Délai plus court', 'statut' => StatutAvenant::EnAttente, 'saisi_par' => $this->regulateur->id]);
        $migration = require database_path('migrations/2026_11_11_000000_delai_tranches_format_duree.php');
        $migration->down();

        $json = fn (string $table, int $id, string $colonne) => json_decode((string) DB::table($table)->where('id', $id)->value($colonne), true);
        $version = DB::table('tranche_versions')->where('tranche_id', $tranche->id)->value('id');
        $this->assertTrue(Schema::hasColumn('tranches', 'delai_paiement_heures'));
        $this->assertSame(36, (int) DB::table('tranches')->where('id', $tranche->id)->value('delai_paiement_heures'));
        $this->assertSame(36, $json('tranche_versions', $version, 'parametres')['delai_paiement_heures']);
        $this->assertSame(24, $json('avenants', $avenant->id, 'nouvelle_valeur')['delai_paiement_heures']);

        // Conversion : 36 heures → valeur 36, durée HEURE, 129 600 secondes, partout.
        $migration->up();
        $heure = Duree::parCode('HEURE')->id;
        $this->assertFalse(Schema::hasColumn('tranches', 'delai_paiement_heures'));
        $this->assertSame([36, $heure, 129_600], array_map('intval', array_values((array) DB::table('tranches')->where('id', $tranche->id)
            ->first(['delai_paiement_valeur', 'delai_paiement_duree_id', 'delai_paiement_secondes']))));
        $parametres = $json('tranche_versions', $version, 'parametres');
        $this->assertSame([36, $heure, 129_600], [$parametres['delai_paiement_valeur'], $parametres['delai_paiement_duree_id'], $parametres['delai_paiement_secondes']]);
        $this->assertArrayNotHasKey('delai_paiement_heures', $parametres);
        $this->assertSame(['delai_paiement_valeur' => 24, 'delai_paiement_duree_id' => $heure, 'delai_paiement_secondes' => 86_400], $json('avenants', $avenant->id, 'nouvelle_valeur'));
        $this->assertSame(36, $json('avenants', $avenant->id, 'ancienne_valeur')['delai_paiement_valeur']);

        // Conversion tracée ; les versions figées restent en ajout seul.
        $this->assertTrue(DB::table('audit_logs')->where('action', 'tranches.delai_converti')->exists());
        $this->expectException(QueryException::class);
        DB::table('tranche_versions')->where('id', $version)->update(['motif' => 'modification interdite']);
    }

    public function test_avenant_sur_le_delai_applique_au_nouveau_format(): void
    {
        $this->en($this->regulateur)->post(route('admin.tranches.store'), $this->formulaire())->assertSessionHasNoErrors();
        $tranche = Tranche::query()->latest('id')->firstOrFail();
        $this->en($this->regulateur)->post(route('admin.tranches.soumettre', $tranche));
        $this->en($this->validateur)->post(route('admin.tranches.confirmer', $tranche));

        $this->en($this->regulateur)->post(route('admin.tranches.avenant.store', $tranche), [...$this->formulaire(['delai_paiement_valeur' => 3, 'delai_paiement_duree' => 'JOUR']), 'motif' => 'Délai plus long'])
            ->assertSessionHas('succes');
        $avenant = Avenant::query()->sole();
        $this->assertSame([3, Duree::parCode('JOUR')->id, 259_200],
            [$avenant->nouvelle_valeur['delai_paiement_valeur'], $avenant->nouvelle_valeur['delai_paiement_duree_id'], $avenant->nouvelle_valeur['delai_paiement_secondes']]);
        $this->en($this->validateur)->post(route('admin.tranches.avenant.confirmer', [$tranche, $avenant]))->assertSessionHas('succes');
        $this->assertSame([259_200, '3 jours'], [$tranche->refresh()->delai_paiement_secondes, $tranche->libelleDelaiPaiement()]);
    }
}
