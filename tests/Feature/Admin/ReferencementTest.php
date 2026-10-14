<?php

namespace Tests\Feature\Admin;

use App\Enums\Role;
use App\Enums\StatutBoutique;
use App\Enums\StatutSite;
use App\Models\Site;
use App\Models\TypeBoutique;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\ActeursParametrage;
use Tests\TestCase;

/** Groupe « Référencement » : anciennes adresses redirigées, liste de toutes les boutiques filtrable. */
class ReferencementTest extends TestCase
{
    use ActeursParametrage, RefreshDatabase;

    public function test_les_anciennes_adresses_redirigent_vers_les_nouvelles(): void
    {
        $this->creerActeurs();
        $gestionnaire = User::factory()->role(Role::GestionnaireSites)->create();
        $site = Site::create(['statut' => StatutSite::Brouillon, 'code' => 'RAA', 'nom' => 'Raaga', 'ville' => 'Ouagadougou', 'cree_par' => $gestionnaire->id]);

        foreach ([
            '/admin/sites' => '/admin/referencement/sites',
            '/admin/sites/creer' => '/admin/referencement/sites/creer',
            '/admin/sites/'.$site->id => '/admin/referencement/sites/'.$site->id,
            '/admin/sites/'.$site->id.'/excel/import' => '/admin/referencement/sites/'.$site->id.'/excel/import',
            '/admin/sites/types' => '/admin/referencement/types',
            '/admin/sites/types/3' => '/admin/referencement/types/3',
            '/admin/sites/excel/import' => '/admin/referencement/imports/sites/import',
            '/admin/sites/excel/boutiques/modele' => '/admin/referencement/imports/boutiques/modele',
            '/admin/boutiques/grilles' => '/admin/referencement/grille',
            '/admin/boutiques/grilles/'.$site->id => '/admin/referencement/grille/'.$site->id,
        ] as $ancienne => $nouvelle) {
            $this->en($gestionnaire)->get($ancienne)->assertStatus(301)->assertRedirect(url($nouvelle));
        }

        // La nouvelle adresse répond ; la recherche est conservée.
        $this->en($gestionnaire)->get('/admin/sites?page=2')->assertRedirect(url('/admin/referencement/sites?page=2'));
        $this->en($gestionnaire)->get('/admin/referencement/sites/'.$site->id)->assertOk()->assertSee('Raaga');
    }

    public function test_la_liste_des_boutiques_se_filtre_par_site(): void
    {
        $this->creerActeurs();
        $type = TypeBoutique::create(['code' => 'BOUT', 'libelle' => 'Boutique']);
        foreach (['RAA', 'SAR'] as $code) {
            $site = Site::create(['statut' => StatutSite::Brouillon, 'code' => $code, 'nom' => 'Marché '.$code, 'ville' => 'Ouagadougou', 'cree_par' => $this->regulateur->id]);
            foreach ([1, 2] as $n) {
                $site->boutiques()->create(['niveau' => 'R1', 'bloc' => 'A', 'numero' => sprintf('%03d', $n), 'surface' => 9, 'type_boutique_id' => $type->id, 'statut' => StatutBoutique::Disponible]);
            }
        }
        $sar = Site::query()->where('code', 'SAR')->sole();

        // Tous sites confondus.
        $this->en($this->validateur)->get(route('admin.referencement.boutiques'))->assertOk()
            ->assertSee('4 boutiques')->assertSee('RAA-R1-A-001')->assertSee('SAR-R1-A-002');

        // Filtré par site : seules ses boutiques, et l'export suit le même filtre.
        $this->en($this->validateur)->get(route('admin.referencement.boutiques', ['site' => $sar->id]))->assertOk()
            ->assertSee('2 boutiques')->assertSee('SAR-R1-A-001')->assertDontSee('RAA-R1-A-001');
        $this->en($this->validateur)->get(route('admin.referencement.boutiques', ['site' => $sar->id, 'q' => '002']))->assertOk()
            ->assertSee('1 boutique')->assertSee('SAR-R1-A-002')->assertDontSee('SAR-R1-A-001');
        $this->en($this->validateur)->get(route('admin.referencement.boutiques.export', ['site' => $sar->id]))->assertOk()
            ->assertHeader('content-disposition');
    }
}
