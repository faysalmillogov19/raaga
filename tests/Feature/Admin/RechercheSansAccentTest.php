<?php

namespace Tests\Feature\Admin;

use App\Enums\Role;
use App\Enums\StatutSite;
use App\Models\Site;
use App\Models\User;
use App\Support\Recherche;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\ConnexionBackoffice;
use Tests\TestCase;

/** Toutes les recherches « contient » ignorent la casse et les accents (PostgreSQL : unaccent), via App\Support\Recherche. */
class RechercheSansAccentTest extends TestCase
{
    use ConnexionBackoffice, RefreshDatabase;

    public function test_l_outil_ignore_casse_et_accents_et_echappe_les_jokers(): void
    {
        $this->assertSame('pgsql', DB::getDriverName());
        User::factory()->create(['name' => 'Awa OUÉDRAOGO']);
        User::factory()->create(['name' => 'Ali ZONGO_100%']);

        $noms = fn (string $terme) => User::query()->whereContient('name', $terme)->pluck('name')->all();
        foreach (['ouedraogo', 'OUEDRAOGO', 'Ouédraogo', 'awa ouéd'] as $terme) {
            $this->assertSame(['Awa OUÉDRAOGO'], $noms($terme), $terme);
        }
        // « % » et « _ » saisis sont cherchés tels quels, jamais comme jokers.
        $this->assertSame(['Ali ZONGO_100%'], $noms('_100%'));
        $this->assertSame([], $noms('o%o'));
        $this->assertSame('%a\%b\_c%', Recherche::motif('a%b_c'));
    }

    public function test_recherches_de_l_assistance_et_des_utilisateurs(): void
    {
        $this->connecterBackoffice(Role::SuperAdmin);
        User::factory()->role(Role::Citoyen)->create(['name' => 'Awa OUÉDRAOGO', 'nom' => 'OUÉDRAOGO', 'prenoms' => 'Awa Éléonore']);
        User::factory()->role(Role::Citoyen)->create(['name' => 'Ali ZONGO']);
        User::factory()->role(Role::Regulateur)->create(['name' => 'Hélène KABORÉ', 'email' => 'helene.kabore@raaga.bf']);

        $this->get('/admin/assistance?q=ouedraogo')->assertSee('Awa OUÉDRAOGO')->assertDontSee('Ali ZONGO');
        $this->get('/admin/assistance?q=eleonore')->assertSee('Awa OUÉDRAOGO');
        $this->get(route('admin.utilisateurs', ['q' => 'helene kabore']))->assertSee('Hélène KABORÉ');
        $this->get(route('admin.recherche', ['q' => 'HELENE']))->assertSee('Hélène KABORÉ');
    }

    public function test_recherches_des_sites_et_des_imports(): void
    {
        $this->connecterBackoffice(Role::GestionnaireSites);
        $createur = User::factory()->role(Role::GestionnaireSites)->create()->id;
        Site::query()->create(['code' => 'SEB', 'nom' => 'Marché de Sébé', 'region' => 'Centre', 'ville' => 'Bobo-Dioulasso', 'statut' => StatutSite::Brouillon, 'cree_par' => $createur]);
        Site::query()->create(['code' => 'RAA', 'nom' => 'Marché Raaga', 'region' => 'Centre', 'ville' => 'Ouagadougou', 'statut' => StatutSite::Brouillon, 'cree_par' => $createur]);

        // Liste en tableau de données (colonnes recherchables), recherche globale du cadre.
        $this->get(route('admin.sites', ['q' => 'marche de sebe']))->assertSee('Marché de Sébé')->assertDontSee('Marché Raaga');
        $this->get(route('admin.recherche', ['q' => 'sebe']))->assertSee('Marché de Sébé')->assertDontSee('Marché Raaga');
        // Journal des imports : recherche par auteur.
        $auteur = User::factory()->role(Role::GestionnaireSites)->create(['name' => 'Inès SAWADOGO']);
        DB::table('audit_logs')->insert(['acteur_id' => $auteur->id, 'action' => 'sites.importes', 'entite_type' => 'Site', 'entite_id' => null,
            'nouvelle_valeur' => json_encode(['fichier' => 'sites-ines.xlsx', 'sites_crees' => 1]), 'created_at' => now()]);
        $this->get(route('admin.referencement.imports.entite', ['entite' => 'sites', 'q' => 'ines']))->assertSee('sites-ines.xlsx');
        $this->get(route('admin.referencement.imports.entite', ['entite' => 'sites', 'q' => 'zongo']))->assertDontSee('sites-ines.xlsx');
    }
}
