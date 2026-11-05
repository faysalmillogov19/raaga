<?php

namespace Tests\Feature\Admin;

use App\Enums\Role;
use App\Models\AuditLog;
use App\Models\Greffe;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\ConnexionBackoffice;
use Tests\TestCase;

/**
 * Greffes du RCCM (réunion du 05/11/2026) : liste initialisée (Ouagadougou, Bobo-Dioulasso, « Autre (préciser) ») ;
 * SUPER_ADMIN l'administre ; ADMIN la consulte avec les greffes saisis en « Autre » ; une précision « Autre » ajoutée à la
 * liste rattache les comptes qui l'avaient saisie ; « Autre » jamais désactivé.
 */
class GreffesTest extends TestCase
{
    use ConnexionBackoffice, RefreshDatabase;

    public function test_liste_initiale_et_option_autre_avec_precision(): void
    {
        $this->assertSame(['Tribunal de commerce de Ouagadougou', 'Tribunal de commerce de Bobo-Dioulasso', 'Autre (préciser)'], Greffe::proposes()->values()->all());
        $autre = Greffe::idAutre();
        $citoyen = User::factory()->role(Role::Citoyen)->create();
        // « Autre » : précision obligatoire.
        $champs = ['rccm' => 'BF KDG 2022 A 5', 'rccm_immatricule_le' => '2022-02-02', 'rccm_greffe_id' => (string) $autre];
        $this->actingAs($citoyen)->post(route('espace.verification.rccm'), $champs)->assertSessionHasErrors('rccm_greffe_autre');
        $this->actingAs($citoyen)->get(route('espace.verification'))->assertSee('data-champ-greffe', false)->assertSee('Autre (préciser)', false);
    }

    public function test_admin_voit_les_greffes_autres_super_admin_les_ajoute_a_la_liste(): void
    {
        $autre = Greffe::idAutre();
        foreach (range(1, 2) as $i) {
            User::factory()->role(Role::Citoyen)->create(['rccm' => 'BF KDG 2022 A '.$i, 'rccm_greffe_id' => $autre, 'rccm_greffe_autre' => 'Tribunal de commerce de Koudougou']);
        }

        // ADMIN : lecture, greffes « Autre » visibles, aucune action.
        $this->connecterBackoffice(Role::Admin);
        $this->get(route('admin.greffes'))->assertOk()->assertSee('data-greffes-autres', false)->assertSee('Tribunal de commerce de Koudougou')
            ->assertDontSee(route('admin.greffes.autre'), false)->assertDontSee('Ajouter un greffe');
        $this->post(route('admin.greffes.store'), ['libelle' => 'Greffe X'])->assertForbidden();
        foreach ([Role::Validateur, Role::Regulateur] as $role) {
            $this->connecterBackoffice($role);
            $this->get(route('admin.greffes'))->assertForbidden();
        }

        // SUPER_ADMIN : ajoute la précision à la liste ; les comptes y sont rattachés.
        $this->connecterBackoffice(Role::SuperAdmin);
        $this->get(route('admin.greffes'))->assertOk()->assertSee(route('admin.greffes.autre'), false);
        $this->post(route('admin.greffes.autre'), ['valeur' => 'Tribunal de commerce de Koudougou'])->assertRedirect(route('admin.greffes'));
        $nouveau = Greffe::query()->where('libelle', 'Tribunal de commerce de Koudougou')->sole();
        $this->assertSame(2, User::query()->where('rccm_greffe_id', $nouveau->id)->whereNull('rccm_greffe_autre')->count());
        $this->assertSame(1, AuditLog::query()->where('action', 'greffe.autre_ajoute')->count());
        $this->get(route('admin.greffes'))->assertSee('Aucun greffe saisi en « Autre ».', false);
    }

    public function test_super_admin_ajoute_modifie_desactive_jamais_autre(): void
    {
        $this->connecterBackoffice(Role::SuperAdmin);
        $this->post(route('admin.greffes.store'), ['libelle' => '  Tribunal de commerce   de Fada  '])->assertSessionHasNoErrors();
        $greffe = Greffe::query()->where('libelle', 'Tribunal de commerce de Fada')->sole();
        $this->post(route('admin.greffes.store'), ['libelle' => 'tribunal de commerce de fada'])->assertSessionHasErrors('libelle');
        $this->put(route('admin.greffes.update', $greffe), ['libelle' => 'Tribunal de commerce de Fada N’Gourma'])->assertSessionHasNoErrors();
        $this->post(route('admin.greffes.desactiver', $greffe))->assertRedirect();
        $this->assertNotContains($greffe->fresh()->libelle, Greffe::proposes()->all());

        $autre = Greffe::query()->where('code', Greffe::AUTRE)->sole();
        $this->post(route('admin.greffes.desactiver', $autre))->assertSessionHas('erreur');
        $this->assertTrue($autre->fresh()->actif);
        $this->put(route('admin.greffes.update', $autre), ['libelle' => 'Autre'])->assertStatus(409);
    }
}
