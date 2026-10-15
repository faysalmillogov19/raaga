<?php

namespace Tests\Feature\Admin;

use App\Enums\Role;
use App\Enums\StatutTypeBoutique;
use App\Models\Niveau;
use App\Models\ParametrageDecision;
use App\Models\ReferentielVersion;
use App\Models\TypeBoutique;
use App\Models\Unite;
use App\Models\User;
use App\Models\ZoneSite;
use App\Support\AValider;
use Database\Seeders\NiveauxSeeder;
use Database\Seeders\TypesBoutiqueSeeder;
use Database\Seeders\UnitesSeeder;
use Database\Seeders\ZonesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Tests\Support\ActeursParametrage;
use Tests\TestCase;

/** Valeurs « par défaut » des seeders : confirmées d'office, hors circuit ; leurs modifications passent par le circuit. */
class ValeursParDefautTest extends TestCase
{
    use ActeursParametrage, RefreshDatabase;

    private const SEEDERS = [TypesBoutiqueSeeder::class, NiveauxSeeder::class, ZonesSeeder::class, UnitesSeeder::class];

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        $this->creerActeurs();
        $this->seed(self::SEEDERS);
    }

    public function test_elements_par_defaut_confirmes_des_le_seeder_sans_circuit(): void
    {
        foreach ([TypeBoutique::class, Niveau::class, ZoneSite::class, Unite::class] as $classe) {
            $elements = $classe::query()->where('par_defaut', true)->get();
            $this->assertNotEmpty($elements, $classe);
            foreach ($elements as $e) {
                $this->assertSame(StatutTypeBoutique::Valide, $e->statut, $classe.' '.$e->code);
                $this->assertGreaterThanOrEqual(1, $e->version, $classe.' '.$e->code);
                $versions = $e->versions();
                $premiere = ($versions instanceof Collection ? $versions : $versions->get())->sortBy('numero')->first();
                $this->assertNull($premiere->auteur_id);
                $this->assertSame(__('admin.types.motifs.defaut'), $premiere->motif);
            }
        }

        // Ni « À valider », ni décision, ni notification.
        $this->assertSame(0, AValider::total($this->validateur));
        $this->en($this->validateur)->get(route('admin.a-valider.rubrique', 'referentiels'))->assertOk()->assertSee('Rien à valider dans cette rubrique.');
        $this->assertSame(0, ParametrageDecision::query()->count());
        Notification::assertNothingSent();

        // Auteur affiché : le système.
        $niveau = Niveau::query()->where('par_defaut', true)->firstOrFail();
        $this->en($this->validateur)->get(route('admin.referencement.zones.show', ['niveaux', $niveau->id]))->assertSee('Système (valeur par défaut)');

        // Idempotent : rien de créé ni de modifié au second passage.
        $versions = ReferentielVersion::query()->count();
        $this->seed(self::SEEDERS);
        $this->assertSame($versions, ReferentielVersion::query()->count());
    }

    public function test_migration_confirme_les_elements_par_defaut_en_attente(): void
    {
        $niveau = Niveau::query()->where('par_defaut', true)->firstOrFail();
        $ancienne = ReferentielVersion::query()->where('objet_type', 'niveau')->where('objet_id', $niveau->id)->sole();
        DB::table('niveaux')->where('id', $niveau->id)->update(['statut' => 'A_VALIDER']);
        $unite = Unite::query()->create(['code' => 'FORFAIT', 'libelle' => 'Forfait', 'actif' => true, 'par_defaut' => true, 'statut' => StatutTypeBoutique::AValider]);
        $this->assertSame(2, AValider::total($this->validateur));

        (require database_path('migrations/2026_10_28_000000_valeurs_par_defaut_confirmees.php'))->confirmerParDefaut();

        $this->assertSame(StatutTypeBoutique::Valide, $niveau->refresh()->statut);
        $this->assertSame(StatutTypeBoutique::Valide, $unite->refresh()->statut);
        $this->assertSame(1, $unite->version);
        $this->assertNull($unite->versions()->sole()->auteur_id);
        $this->assertSame(2, $niveau->version);
        $this->assertEquals($ancienne->getAttributes(), $ancienne->fresh()->getAttributes()); // version existante intacte
        $this->assertSame(0, ParametrageDecision::query()->count());
        $this->assertSame(0, AValider::total($this->validateur));
        Notification::assertNothingSent();
    }

    public function test_modification_d_un_element_par_defaut_soumise_a_validation(): void
    {
        $gestionnaire = User::factory()->role(Role::GestionnaireSites)->create();
        $niveau = Niveau::query()->where('par_defaut', true)->firstOrFail();
        $libelle = $niveau->libelle;

        // Libellé : non appliqué, en attente dans « À valider ».
        $this->en($gestionnaire)->put(route('admin.referencement.zones.update', ['niveaux', $niveau->id]), ['libelle' => 'Rez-de-chaussée rénové', 'rang' => $niveau->rang])
            ->assertSessionHas('succes', __('admin.zones.messages.modification_soumise'));
        $this->assertSame($libelle, $niveau->refresh()->libelle);
        $this->assertTrue($niveau->estUtilisable());
        $this->en($this->validateur)->get(route('admin.a-valider.rubrique', 'referentiels'))->assertSee($niveau->code.' · '.$libelle)
            ->assertSee('href="'.route('admin.a-valider.rubrique', ['rubrique' => 'referentiels', 'detail' => 'niveaux:'.$niveau->id]).'"', false);
        $this->en($this->validateur)->get(route('admin.a-valider.rubrique', ['rubrique' => 'referentiels', 'detail' => 'niveaux:'.$niveau->id]))
            ->assertSee('data-champ-modifie="libelle"', false)->assertSee('Rez-de-chaussée rénové');

        // Validation : la modification s'applique.
        $this->en($this->validateur)->post(route('admin.referencement.zones.confirmer', ['niveaux', $niveau->id]))->assertSessionHas('succes');
        $this->assertSame('Rez-de-chaussée rénové', $niveau->refresh()->libelle);
        $this->assertNull($niveau->modification_demandee);

        // Désactivation d'un type par défaut : soumise, puis rejetée ; le type reste actif.
        $type = TypeBoutique::query()->where('par_defaut', true)->firstOrFail();
        $this->en($gestionnaire)->post(route('admin.sites.types.desactiver', $type))->assertSessionHas('succes');
        $this->assertTrue($type->refresh()->actif);
        $this->assertSame(['actif' => false], $type->modification_demandee);
        $this->assertSame(1, AValider::total($this->validateur));
        $this->en($this->validateur)->post(route('admin.sites.types.rejeter', $type), ['motif' => 'Type encore utilisé'])->assertSessionHas('succes');
        $this->assertTrue($type->refresh()->actif);
        $this->assertNull($type->modification_demandee);
        $this->assertSame(0, AValider::total($this->validateur));
    }
}
