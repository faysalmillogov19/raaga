<?php

namespace Tests\Feature\Admin;

use App\Enums\Role;
use App\Enums\StatutBoutique;
use App\Enums\StatutSite;
use App\Enums\StatutTypeBoutique;
use App\Models\Boutique;
use App\Models\Site;
use App\Models\TypeBoutique;
use App\Models\User;
use App\Services\Backoffice\RegleGestionException;
use Database\Seeders\TypesBoutiqueSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\Support\ActeursParametrage;
use Tests\TestCase;

class TypesBoutiqueTest extends TestCase
{
    use ActeursParametrage, RefreshDatabase;

    private User $gestionnaire;

    protected function setUp(): void
    {
        parent::setUp();

        $this->creerActeurs();
        $this->gestionnaire = User::factory()->role(Role::GestionnaireSites)->create();
        $this->seed(TypesBoutiqueSeeder::class);
    }

    private function type(string $code): TypeBoutique
    {
        return TypeBoutique::query()->where('code', $code)->sole();
    }

    public function test_le_seeder_est_rejouable_sans_doublon_ni_ecrasement(): void
    {
        $this->assertSame(['BOUT', 'CF', 'ETAL', 'MAG', 'PF', 'POSTE', 'PP', 'PV', 'REST'], TypeBoutique::query()->orderBy('code')->pluck('code')->all());
        $this->assertTrue(TypeBoutique::all()->every(fn ($t) => $t->par_defaut && $t->actif && $t->statut === StatutTypeBoutique::Valide));
        $this->assertFalse($this->type('CF')->attribuable);

        // Le GESTIONNAIRE modifie et désactive des types par défaut ; le VALIDATEUR confirme.
        $this->en($this->gestionnaire)->put(route('admin.sites.types.update', $this->type('BOUT')), ['libelle' => 'Boutique standard', 'ordre' => 5])
            ->assertSessionHas('succes');
        $this->en($this->gestionnaire)->post(route('admin.sites.types.desactiver', $this->type('MAG')))->assertSessionHas('succes');
        $this->en($this->validateur)->post(route('admin.sites.types.confirmer', $this->type('BOUT')))->assertSessionHas('succes');
        $this->en($this->validateur)->post(route('admin.sites.types.confirmer', $this->type('MAG')))->assertSessionHas('succes');

        // Le seeder rejoué ne crée aucun doublon et n'écrase rien.
        $this->seed(TypesBoutiqueSeeder::class);
        $this->seed(TypesBoutiqueSeeder::class);
        $this->assertSame(9, TypeBoutique::query()->count());
        $this->assertSame(['Boutique standard', 5], [$this->type('BOUT')->libelle, $this->type('BOUT')->ordre]);
        $this->assertFalse($this->type('MAG')->actif);
    }

    public function test_le_code_n_est_jamais_modifiable(): void
    {
        $bout = $this->type('BOUT');
        $this->en($this->gestionnaire)->put(route('admin.sites.types.update', $bout), ['code' => 'XXX', 'libelle' => 'Boutique', 'ordre' => 1])
            ->assertSessionHas('succes');
        $this->assertSame('BOUT', $bout->fresh()->code);

        $this->expectException(RegleGestionException::class);
        $bout->fresh()->forceFill(['code' => 'BTQ'])->save();
    }

    public function test_la_suppression_est_refusee_si_le_type_est_utilise(): void
    {
        // Type par défaut : jamais supprimé, même inutilisé.
        $this->en($this->gestionnaire)->delete(route('admin.sites.types.destroy', $this->type('REST')))->assertSessionHas('erreur');

        // Type ajouté par le gestionnaire, validé, puis utilisé par une boutique.
        $this->en($this->gestionnaire)->post(route('admin.sites.types.store'), ['code' => 'KIOS', 'libelle' => 'Kiosque', 'attribuable' => 1])->assertSessionHas('succes');
        $kiosque = $this->type('KIOS');
        $this->assertSame(StatutTypeBoutique::AValider, $kiosque->statut);
        $this->en($this->validateur)->post(route('admin.sites.types.confirmer', $kiosque))->assertSessionHas('succes');
        $site = Site::create(['statut' => StatutSite::Brouillon, 'code' => 'RAA', 'nom' => 'Raaga', 'ville' => 'Ouagadougou', 'cree_par' => $this->gestionnaire->id]);
        $site->boutiques()->create(['niveau' => 'RC', 'bloc' => 'K', 'numero' => '001', 'surface' => 4, 'type_boutique_id' => $kiosque->id, 'statut' => StatutBoutique::Disponible]);

        $this->en($this->gestionnaire)->delete(route('admin.sites.types.destroy', $kiosque))->assertSessionHas('erreur');
        $this->assertModelExists($kiosque);

        // Inutilisé et non « par défaut » : supprimé, son code n'est jamais réutilisé.
        $this->en($this->gestionnaire)->post(route('admin.sites.types.store'), ['code' => 'TEMP', 'libelle' => 'Temporaire', 'attribuable' => 1]);
        $this->en($this->gestionnaire)->delete(route('admin.sites.types.destroy', $this->type('TEMP')))->assertSessionHas('succes');
        $this->en($this->gestionnaire)->post(route('admin.sites.types.store'), ['code' => 'TEMP', 'libelle' => 'Autre', 'attribuable' => 1])
            ->assertSessionHasErrors('code');
    }

    public function test_un_type_desactive_est_refuse_a_l_import(): void
    {
        Storage::fake('local');
        $site = Site::create(['statut' => StatutSite::Brouillon, 'code' => 'RAA', 'nom' => 'Raaga', 'ville' => 'Ouagadougou', 'cree_par' => $this->gestionnaire->id]);
        $this->en($this->gestionnaire)->post(route('admin.sites.types.desactiver', $this->type('ETAL')))->assertSessionHas('succes');
        $this->en($this->validateur)->post(route('admin.sites.types.confirmer', $this->type('ETAL')))->assertSessionHas('succes');

        $classeur = new Spreadsheet;
        $classeur->getActiveSheet()->setTitle('Locaux')->fromArray([
            ['Code (calculé)', 'Site', 'Niveau', 'Bloc ou zone', 'Numéro', 'Type', 'Surface (m²)', 'Planche', 'Observations', 'Contrôle'],
            [null, 'RAA', 'RC', 'E', 1, 'ETAL', 2],
            [null, 'RAA', 'RC', 'A', 1, 'BOUT', 9],
        ]);
        $chemin = tempnam(sys_get_temp_dir(), 'locaux').'.xlsx';
        (new Xlsx($classeur))->save($chemin);
        $this->en($this->gestionnaire)->post(route('admin.referencement.boutiques.excel.deposer'), ['site_id' => $site->id, 'fichier' => new UploadedFile($chemin, 'locaux.xlsx', null, null, true)]);

        $this->en($this->gestionnaire)->get(route('admin.referencement.boutiques.excel.import', $site))->assertOk()->assertSee('Type ETAL désactivé : il ne peut plus être utilisé.');
        $this->en($this->gestionnaire)->post(route('admin.referencement.boutiques.excel.import.confirmer', $site))->assertSessionHas('erreur');
        $this->assertSame(0, Boutique::query()->count());

        // Absent aussi de la saisie : refusé dans le formulaire d'ajout d'une boutique.
        $this->en($this->gestionnaire)->post(route('admin.referencement.boutiques.store', $site), ['niveau' => 'RC', 'bloc' => 'E', 'numero' => 1, 'type_boutique_id' => $this->type('ETAL')->id, 'etat_physique' => 'LIVREE'])
            ->assertSessionHasErrors('type_boutique_id');
    }

    public function test_les_autres_profils_ont_un_acces_en_lecture_seule(): void
    {
        $bout = $this->type('BOUT');
        foreach ([$this->validateur, $this->regulateur, User::factory()->role(Role::SuperAdmin)->create()] as $profil) {
            $this->en($profil)->get(route('admin.sites.types'))->assertOk()->assertSee('Par défaut')->assertDontSee('Ajouter le type');
            $this->en($profil)->get(route('admin.sites.types.show', $bout))->assertOk()->assertDontSee('Modifier le type');
            $this->en($profil)->post(route('admin.sites.types.store'), ['code' => 'NEW', 'libelle' => 'Nouveau', 'attribuable' => 1])->assertForbidden();
            $this->en($profil)->put(route('admin.sites.types.update', $bout), ['libelle' => 'X', 'ordre' => 1])->assertForbidden();
            $this->en($profil)->post(route('admin.sites.types.desactiver', $bout))->assertForbidden();
            $this->en($profil)->delete(route('admin.sites.types.destroy', $bout))->assertForbidden();
        }
        // Le gestionnaire ne valide jamais ses propres demandes.
        $this->en($this->gestionnaire)->post(route('admin.sites.types.confirmer', $bout))->assertForbidden();
        $this->assertSame('Boutique', $bout->fresh()->libelle);
    }
}
