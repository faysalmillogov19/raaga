<?php

namespace Tests\Feature\Admin;

use App\Enums\Droit;
use App\Enums\Role;
use App\Models\AuditLog;
use App\Models\Commune;
use App\Models\FamilleSecteur;
use App\Models\Guichet;
use App\Models\SecteurActivite;
use App\Models\User;
use App\Support\MenuBackoffice;
use Database\Seeders\CommunesSeeder;
use Database\Seeders\SecteursActiviteSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\ConnexionBackoffice;
use Tests\TestCase;

/**
 * Secteurs d'activité des commerçants et guichets agréés (04/11/2026) : écrans du SUPER_ADMIN, sans validation, dans
 * « Paramètres d'administration ». Aucun autre profil n'y accède. Chaque action est journalisée.
 */
class SecteursEtGuichetsTest extends TestCase
{
    use ConnexionBackoffice, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([SecteursActiviteSeeder::class, CommunesSeeder::class]);
    }

    private function commune(string $nom): int
    {
        return Commune::query()->where('nom', $nom)->value('id');
    }

    public function test_seul_le_super_admin_gere_les_secteurs_et_les_guichets(): void
    {
        foreach (Role::backoffice() as $role) {
            foreach ([Droit::SecteursGerer, Droit::GuichetsGerer] as $droit) {
                $this->assertSame($role === Role::SuperAdmin, in_array($droit, Droit::pour($role), true), $role->value.' '.$droit->value);
            }
        }
        $this->connecterBackoffice(Role::Regulateur);
        $this->get(route('admin.secteurs'))->assertForbidden();
        $this->get(route('admin.guichets-agrees'))->assertForbidden();
        $this->post(route('admin.guichets-agrees.store'), ['nom' => 'X'])->assertForbidden();
    }

    public function test_les_deux_ecrans_sont_dans_les_parametres_d_administration_du_super_admin(): void
    {
        $user = $this->connecterBackoffice(Role::SuperAdmin);
        $cles = collect(MenuBackoffice::pour($user))->flatten(1)->where('sous_menu', 'parametres_generaux')->pluck('cle')->all();
        $this->assertContains('secteurs', $cles);
        $this->assertContains('guichets_agrees', $cles);

        $this->get(route('admin.secteurs'))->assertOk()->assertSee('Secteurs d’activité')->assertSee('Ajouter un secteur')->assertSee('Autre activité (à préciser)');
        $this->get(route('admin.guichets-agrees'))->assertOk()->assertSee('Guichets agréés')->assertSee('Ajouter un guichet');
    }

    public function test_le_super_admin_ajoute_renomme_et_desactive_un_secteur(): void
    {
        $user = $this->connecterBackoffice(Role::SuperAdmin);
        $famille = FamilleSecteur::query()->orderBy('ordre')->value('id');

        $this->post(route('admin.secteurs.store'), ['libelle' => '  Vente de   pagnes ', 'famille_secteur_id' => $famille])->assertSessionHas('succes');
        $secteur = SecteurActivite::query()->where('libelle', 'Vente de pagnes')->firstOrFail();
        $this->assertSame(['VENTE_DE_PAGNES', true], [$secteur->code, $secteur->actif]);
        $this->assertSame((int) SecteurActivite::query()->where('famille_secteur_id', $famille)->max('ordre'), $secteur->ordre);
        $this->assertArrayHasKey($secteur->id, collect(SecteurActivite::parFamille())->reduce(fn ($c, $s) => $c + $s, []));

        // Libellé déjà pris, sans tenir compte des majuscules.
        $this->post(route('admin.secteurs.store'), ['libelle' => 'VENTE DE PAGNES', 'famille_secteur_id' => $famille])->assertSessionHasErrors('libelle');

        $this->put(route('admin.secteurs.update', $secteur), ['libelle' => 'Vente de pagnes et tissus', 'famille_secteur_id' => $famille, 'ordre' => 3])->assertSessionHas('succes');
        $this->assertSame(['Vente de pagnes et tissus', 'VENTE_DE_PAGNES', 3], [$secteur->fresh()->libelle, $secteur->fresh()->code, $secteur->fresh()->ordre]);

        $this->post(route('admin.secteurs.desactiver', $secteur))->assertSessionHas('succes');
        $this->assertFalse($secteur->fresh()->actif);
        $proposes = collect(SecteurActivite::parFamille())->reduce(fn ($c, $s) => $c + $s, []);
        $this->assertArrayNotHasKey($secteur->id, $proposes);

        $this->post(route('admin.secteurs.activer', $secteur))->assertSessionHas('succes');
        $this->assertTrue($secteur->fresh()->actif);

        $actions = AuditLog::query()->where('acteur_id', $user->id)->where('entite_id', (string) $secteur->id)->pluck('action')->all();
        $this->assertEqualsCanonicalizing(['secteur_activite.cree', 'secteur_activite.modifie', 'secteur_activite.desactive', 'secteur_activite.active'], $actions);
    }

    public function test_autre_activite_n_est_jamais_desactive_et_aucun_secteur_n_est_supprime(): void
    {
        $this->connecterBackoffice(Role::SuperAdmin);
        $autre = SecteurActivite::query()->where('code', SecteurActivite::AUTRE)->firstOrFail();

        $this->post(route('admin.secteurs.desactiver', $autre))->assertSessionHas('erreur');
        $this->assertTrue($autre->fresh()->actif);
        $this->delete(route('admin.secteurs.update', $autre))->assertStatus(405);
    }

    public function test_un_commercant_garde_son_secteur_desactive(): void
    {
        $this->connecterBackoffice(Role::SuperAdmin);
        $secteur = SecteurActivite::query()->where('code', '!=', SecteurActivite::AUTRE)->firstOrFail();
        $commercant = User::factory()->create(['secteur_activite_id' => $secteur->id]);

        $this->post(route('admin.secteurs.desactiver', $secteur))->assertSessionHas('succes');
        $this->assertSame($secteur->id, $commercant->fresh()->secteur_activite_id);
    }

    public function test_le_super_admin_gere_les_guichets_affiches_sur_la_page_publique(): void
    {
        $user = $this->connecterBackoffice(Role::SuperAdmin);
        $donnees = ['nom' => 'Guichet de Gounghin', 'commune_id' => $this->commune('Ouagadougou'), 'adresse' => 'Avenue Kwame Nkrumah',
            'horaires' => 'Du lundi au vendredi, de 8 h à 16 h', 'telephone' => '+226 25 30 00 00'];

        $this->post(route('admin.guichets-agrees.store'), $donnees)->assertSessionHas('succes');
        $guichet = Guichet::query()->where('nom', 'Guichet de Gounghin')->firstOrFail();
        $this->assertTrue($guichet->actif);
        $this->get(route('guichets'))->assertSee('Guichet de Gounghin');
        $this->get(route('admin.guichets-agrees'))->assertSee('Guichet de Gounghin')->assertSee('Ouagadougou');

        $this->put(route('admin.guichets-agrees.update', $guichet), [...$donnees, 'commune_id' => $this->commune('Bobo-Dioulasso'), 'telephone' => ''])->assertSessionHas('succes');
        $this->assertSame([$this->commune('Bobo-Dioulasso'), null], [$guichet->fresh()->commune_id, $guichet->fresh()->telephone]);

        $this->post(route('admin.guichets-agrees.desactiver', $guichet))->assertSessionHas('succes');
        // Le message de confirmation cite le nom : l'adresse prouve l'absence de la liste publique.
        $this->get(route('guichets'))->assertDontSee('Avenue Kwame Nkrumah');
        $this->post(route('admin.guichets-agrees.activer', $guichet))->assertSessionHas('succes');
        $this->get(route('guichets'))->assertSee('Avenue Kwame Nkrumah');

        $this->delete(route('admin.guichets-agrees.destroy', $guichet))->assertSessionHas('succes');
        $this->assertNull(Guichet::query()->find($guichet->id));

        $actions = AuditLog::query()->where('acteur_id', $user->id)->where('entite_type', 'Guichet')->pluck('action')->all();
        $this->assertEqualsCanonicalizing(['guichet.cree', 'guichet.modifie', 'guichet.desactive', 'guichet.active', 'guichet.supprime'], $actions);
    }

    public function test_les_saisies_d_un_guichet_sont_controlees(): void
    {
        $this->connecterBackoffice(Role::SuperAdmin);

        $this->post(route('admin.guichets-agrees.store'), ['nom' => '', 'commune_id' => 999999, 'adresse' => '', 'telephone' => 'abc'])
            ->assertSessionHasErrors(['nom', 'commune_id', 'adresse', 'telephone']);
        $this->assertSame(0, Guichet::query()->count());
    }
}
