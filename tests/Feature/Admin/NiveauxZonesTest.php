<?php

namespace Tests\Feature\Admin;

use App\Enums\Role;
use App\Enums\StatutBoutique;
use App\Enums\StatutSite;
use App\Enums\StatutTypeBoutique;
use App\Models\Boutique;
use App\Models\Niveau;
use App\Models\Site;
use App\Models\TypeBoutique;
use App\Models\User;
use App\Models\ZoneSite;
use App\Services\Backoffice\RegleGestionException;
use Database\Seeders\NiveauxSeeder;
use Database\Seeders\ZonesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\Support\ActeursParametrage;
use Tests\TestCase;

class NiveauxZonesTest extends TestCase
{
    use ActeursParametrage, RefreshDatabase;

    private User $gestionnaire;

    private Site $site;

    private TypeBoutique $type;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        $this->creerActeurs();
        $this->seed([NiveauxSeeder::class, ZonesSeeder::class]);
        $this->gestionnaire = User::factory()->role(Role::GestionnaireSites)->create();
        $this->type = TypeBoutique::create(['code' => 'BOUT', 'libelle' => 'Boutique']);
        $this->site = Site::create(['statut' => StatutSite::Brouillon, 'code' => 'RAA', 'nom' => 'Raaga', 'ville' => 'Ouagadougou', 'cree_par' => $this->gestionnaire->id]);
    }

    private function niveau(string $code): Niveau
    {
        return Niveau::query()->where('code', $code)->sole();
    }

    private function zone(string $code): ZoneSite
    {
        return ZoneSite::query()->where('code', $code)->sole();
    }

    private function fichier(string $onglet, array $lignes): UploadedFile
    {
        $classeur = new Spreadsheet;
        $classeur->getActiveSheet()->setTitle($onglet)->fromArray($lignes);
        $chemin = tempnam(sys_get_temp_dir(), 'ref').'.xlsx';
        (new Xlsx($classeur))->save($chemin);

        return new UploadedFile($chemin, 'fichier.xlsx', null, null, true);
    }

    private function ajouterBoutique(string $niveau, int $numero = 1): TestResponse
    {
        return $this->en($this->gestionnaire)->post(route('admin.sites.boutiques.store', $this->site), [
            'niveau' => $niveau, 'bloc' => 'A', 'numero' => $numero, 'type_boutique_id' => $this->type->id, 'etat_physique' => 'LIVREE',
        ]);
    }

    public function test_les_seeders_sont_rejouables_sans_doublon_ni_ecrasement(): void
    {
        $this->assertSame(['SS', 'RC', 'R1', 'R2', 'R3'], Niveau::query()->orderBy('rang')->pluck('code')->all());
        $this->assertSame([-1, 0, 1, 2, 3], Niveau::query()->orderBy('rang')->pluck('rang')->all());
        $this->assertSame(['A', 'B', 'MAG', 'ETAL', 'BP', 'CF', 'REST'], ZoneSite::query()->orderBy('ordre')->pluck('code')->all());
        $this->assertTrue(Niveau::all()->every->par_defaut && ZoneSite::all()->every->par_defaut);

        // Le GESTIONNAIRE modifie et désactive des éléments par défaut.
        $this->en($this->gestionnaire)->put(route('admin.referencement.zones.update', ['niveaux', $this->niveau('RC')->id]), ['libelle' => 'RDC', 'rang' => 0])->assertSessionHas('succes');
        $this->en($this->gestionnaire)->post(route('admin.referencement.zones.desactiver', ['zones', $this->zone('BP')->id]))->assertSessionHas('succes');

        $this->seed([NiveauxSeeder::class, ZonesSeeder::class]);
        $this->seed([NiveauxSeeder::class, ZonesSeeder::class]);
        $this->assertSame([5, 7], [Niveau::query()->count(), ZoneSite::query()->count()]);
        $this->assertSame('RDC', $this->niveau('RC')->libelle);
        $this->assertFalse($this->zone('BP')->actif);
    }

    public function test_le_code_n_est_jamais_modifiable(): void
    {
        $r1 = $this->niveau('R1');
        $this->en($this->gestionnaire)->put(route('admin.referencement.zones.update', ['niveaux', $r1->id]), ['code' => 'R9', 'libelle' => 'Premier étage', 'rang' => 1])
            ->assertSessionHas('succes');
        $this->assertSame(['R1', 'Premier étage'], [$r1->fresh()->code, $r1->fresh()->libelle]);

        foreach ([$r1->fresh(), $this->zone('A')] as $element) {
            try {
                $element->forceFill(['code' => 'XX'])->save();
                $this->fail('Le code a pu être modifié.');
            } catch (RegleGestionException) {
            }
        }
        $this->assertSame('A', $this->zone('A')->code);
    }

    public function test_la_suppression_est_refusee_si_l_element_est_utilise(): void
    {
        // Par défaut : jamais supprimé, même inutilisé.
        $this->en($this->gestionnaire)->delete(route('admin.referencement.zones.destroy', ['niveaux', $this->niveau('SS')->id]))->assertSessionHas('erreur');
        $this->en($this->gestionnaire)->delete(route('admin.referencement.zones.destroy', ['zones', $this->zone('REST')->id]))->assertSessionHas('erreur');

        // Ajouté puis utilisé par une boutique : refusé.
        $this->en($this->gestionnaire)->post(route('admin.referencement.zones.store', 'niveaux'), ['code' => 'R4', 'libelle' => 'R+4', 'rang' => 4])->assertSessionHas('succes');
        $this->en($this->validateur)->post(route('admin.referencement.zones.confirmer', ['niveaux', $this->niveau('R4')->id]))->assertSessionHas('succes');
        $this->ajouterBoutique('R4')->assertSessionHas('succes');
        $this->en($this->gestionnaire)->delete(route('admin.referencement.zones.destroy', ['niveaux', $this->niveau('R4')->id]))->assertSessionHas('erreur');
        $this->assertModelExists($this->niveau('R4'));

        // Ajouté, jamais utilisé : supprimé.
        $this->en($this->gestionnaire)->post(route('admin.referencement.zones.store', 'zones'), ['code' => 'TMP', 'libelle' => 'Temporaire'])->assertSessionHas('succes');
        $this->en($this->gestionnaire)->delete(route('admin.referencement.zones.destroy', ['zones', $this->zone('TMP')->id]))->assertSessionHas('succes');
        $this->assertFalse(ZoneSite::query()->where('code', 'TMP')->exists());
    }

    public function test_un_niveau_ou_une_zone_desactive_est_refuse_a_l_import(): void
    {
        $existante = $this->site->boutiques()->create(['niveau' => 'R2', 'bloc' => 'A', 'numero' => '001', 'surface' => 9, 'type_boutique_id' => $this->type->id, 'statut' => StatutBoutique::Disponible]);
        $this->en($this->gestionnaire)->post(route('admin.referencement.zones.desactiver', ['niveaux', $this->niveau('R2')->id]))->assertSessionHas('succes');
        $this->en($this->gestionnaire)->post(route('admin.referencement.zones.desactiver', ['zones', $this->zone('BP')->id]))->assertSessionHas('succes');

        // Boutiques : niveau désactivé ou inconnu refusé.
        $this->en($this->gestionnaire)->post(route('admin.sites.import.deposer', $this->site), ['fichier' => $this->fichier('Locaux', [
            ['Code (calculé)', 'Site', 'Niveau', 'Bloc ou zone', 'Numéro', 'Type', 'Surface (m²)'],
            [null, 'RAA', 'R2', 'A', 2, 'BOUT', 9],
            [null, 'RAA', 'R7', 'A', 3, 'BOUT', 9],
        ])]);
        $this->en($this->gestionnaire)->get(route('admin.sites.import', $this->site))->assertOk()
            ->assertSee('Niveau R2 désactivé : il ne peut plus être utilisé.')->assertSee('Niveau R7 inconnu.');
        $this->en($this->gestionnaire)->post(route('admin.sites.import.confirmer', $this->site))->assertSessionHas('erreur');

        // Capacités : zone désactivée refusée.
        $classeur = new Spreadsheet;
        $classeur->getActiveSheet()->setTitle('Sites')->fromArray([['Code site', 'Nom officiel', 'Ville']]);
        $classeur->createSheet()->setTitle('Capacités')->fromArray([['Code site', 'Niveau', 'Zone', 'Type de local', 'Nombre prévu'], ['RAA', 'RC', 'BP', 'BOUT', 10]]);
        $chemin = tempnam(sys_get_temp_dir(), 'ref').'.xlsx';
        (new Xlsx($classeur))->save($chemin);
        $this->en($this->gestionnaire)->post(route('admin.sites.excel.deposer'), ['fichier_sites' => new UploadedFile($chemin, 'sites.xlsx', null, null, true)]);
        $this->en($this->gestionnaire)->get(route('admin.sites.excel.apercu'))->assertOk()->assertSee('Zone BP désactivée : elle ne peut plus être utilisée.');

        $this->assertSame(1, Boutique::query()->count());
        // La boutique existante garde son niveau désactivé, affiché sur la fiche du site ; son code ne change pas.
        $this->en($this->gestionnaire)->get(route('admin.sites.show', $this->site))->assertOk()->assertSee('R+2')->assertSee('RAA-R2-A-001');
        $this->assertSame('RAA-R2-A-001', $existante->fresh()->code);
    }

    public function test_un_nouveau_niveau_est_utilisable_apres_confirmation(): void
    {
        $this->en($this->gestionnaire)->post(route('admin.referencement.zones.store', 'niveaux'), ['code' => 'r4', 'libelle' => 'R+4', 'rang' => 4])->assertSessionHas('succes');
        $r4 = $this->niveau('R4');
        $this->assertSame(StatutTypeBoutique::AValider, $r4->statut);

        // En attente : refusé à la saisie.
        $this->ajouterBoutique('R4')->assertSessionHasErrors('niveau');
        $this->assertSame(0, Boutique::query()->count());

        $this->en($this->validateur)->post(route('admin.referencement.zones.confirmer', ['niveaux', $r4->id]))->assertSessionHas('succes');
        $this->ajouterBoutique('R4')->assertSessionHas('succes');
        $this->assertSame('RAA-R4-A-001', Boutique::query()->sole()->code);
        $this->assertSame(['R+4', 4], [Boutique::query()->sole()->libelleNiveau(), $r4->fresh()->rang]);
        $this->assertSame(2, $r4->fresh()->versions()->count());
    }

    public function test_les_autres_profils_ont_un_acces_en_lecture_seule(): void
    {
        $r1 = $this->niveau('R1');
        $a = $this->zone('A');
        foreach ([$this->validateur, $this->regulateur, User::factory()->role(Role::SuperAdmin)->create()] as $profil) {
            $this->en($profil)->get(route('admin.referencement.niveaux'))->assertOk()->assertSee('R+1')->assertDontSee('Ajouter un niveau')->assertDontSee('>Supprimer<', false);
            $this->en($profil)->get(route('admin.referencement.zones'))->assertOk()->assertSee('Bloc A')->assertDontSee('Ajouter une zone');
            $this->en($profil)->get(route('admin.referencement.zones.show', ['niveaux', $r1->id]))->assertOk()->assertDontSee('Désactiver');
            $this->en($profil)->post(route('admin.referencement.zones.store', 'niveaux'), ['code' => 'R5', 'libelle' => 'R+5', 'rang' => 5])->assertForbidden();
            $this->en($profil)->put(route('admin.referencement.zones.update', ['niveaux', $r1->id]), ['libelle' => 'X', 'rang' => 1])->assertForbidden();
            $this->en($profil)->post(route('admin.referencement.zones.desactiver', ['zones', $a->id]))->assertForbidden();
            $this->en($profil)->delete(route('admin.referencement.zones.destroy', ['zones', $a->id]))->assertForbidden();
        }
        $this->en(User::factory()->role(Role::Admin)->create())->get(route('admin.referencement.niveaux'))->assertForbidden();
        $this->en(User::factory()->role(Role::Admin)->create())->get(route('admin.referencement.zones'))->assertForbidden();
        // Le GESTIONNAIRE ne valide jamais.
        $this->en($this->gestionnaire)->post(route('admin.referencement.zones.confirmer', ['zones', $a->id]))->assertForbidden();
        $this->assertSame(['R+1', true], [$r1->fresh()->libelle, $a->fresh()->actif]);
    }
}
