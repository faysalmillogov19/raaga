<?php

namespace Tests\Feature\Admin;

use App\Enums\Role;
use App\Services\Audit\Journal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\ConnexionBackoffice;
use Tests\TestCase;

/** Imports : un sous-onglet par entité importable (menu dépliant « Imports »), avec l'historique de ses imports. */
class ImportsParEntiteTest extends TestCase
{
    use ConnexionBackoffice, RefreshDatabase;

    public function test_chaque_sous_onglet_montre_ses_imports_et_son_historique(): void
    {
        $gestionnaire = $this->connecterBackoffice(Role::GestionnaireSites);
        $journal = app(Journal::class);
        $journal->ecrire($gestionnaire, 'sites.importes', 'Site', null, null, ['fichier' => 'sites-ouaga.xlsx', 'sites_crees' => 2]);
        $journal->ecrire($gestionnaire, 'boutiques.importees', 'Site', null, null, ['fichier' => 'locaux-raaga.xlsx', 'creees' => 40]);

        $sites = $this->get(route('admin.referencement.imports.entite', 'sites'))->assertOk();
        $sites->assertSee('Imports des sites et capacités prévues')->assertSee('Historique des imports')
            ->assertSee('sites-ouaga.xlsx')->assertDontSee('locaux-raaga.xlsx')
            ->assertSee(route('admin.sites.excel.deposer'), false)->assertDontSee(route('admin.sites.boutiques-excel.deposer'), false);

        $boutiques = $this->get(route('admin.referencement.imports.entite', 'boutiques'))->assertOk();
        $boutiques->assertSee('Imports des boutiques')->assertSee('locaux-raaga.xlsx')->assertDontSee('sites-ouaga.xlsx')
            ->assertSee(route('admin.sites.boutiques-excel.deposer'), false)->assertDontSee(route('admin.sites.excel.deposer'), false)
            // Aucun site à choisir : le site de chaque boutique est lu dans la colonne « Site » du fichier.
            ->assertDontSee('name="site_id"', false)->assertSee('Aucun site à choisir');

        // Menu dépliant « Imports » : deux sous-onglets, celui de la page surligné ; l'ancienne page renvoie au premier.
        $boutiques->assertSee('aria-controls="sous-menu-imports"', false)
            ->assertSee('href="'.route('admin.referencement.imports.entite', 'sites').'"', false);
        $this->assertMatchesRegularExpression('#<a href="'.preg_quote(route('admin.referencement.imports.entite', 'boutiques'), '#').'"[^>]*aria-current="page"#', $boutiques->getContent());
        $this->get(url('admin/referencement/imports'))->assertRedirect(url('admin/referencement/imports/sites'));
        $this->get(url('admin/referencement/imports/autre'))->assertNotFound();
    }

    public function test_lecture_seule_sans_droit_de_saisie(): void
    {
        $this->connecterBackoffice(Role::Validateur);
        $this->get(route('admin.referencement.imports.entite', 'sites'))->assertOk()->assertSee('Historique des imports')
            ->assertDontSee(route('admin.sites.excel.deposer'), false);

        $this->connecterBackoffice(Role::Admin);
        $this->get(route('admin.referencement.imports.entite', 'boutiques'))->assertForbidden();
    }
}
