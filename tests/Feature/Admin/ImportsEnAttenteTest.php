<?php

namespace Tests\Feature\Admin;

use App\Enums\Role;
use App\Enums\StatutSite;
use App\Models\Boutique;
use App\Models\ImportEnAttente;
use App\Models\Site;
use Database\Seeders\ReferentielsSitesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\Support\ConnexionBackoffice;
use Tests\TestCase;

/** Import des boutiques : aperçu impossible à manquer, en attente de confirmation, repris, expiré, remplacé. */
class ImportsEnAttenteTest extends TestCase
{
    use ConnexionBackoffice, RefreshDatabase;

    private const BANDEAU = 'Rien n’est encore enregistré. Vérifiez puis cliquez sur « Confirmer l’import » en bas de la page.';

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        $this->seed(ReferentielsSitesSeeder::class);
        $gestionnaire = $this->connecterBackoffice(Role::GestionnaireSites);
        Site::create(['statut' => StatutSite::Brouillon, 'code' => 'RAA', 'nom' => 'Marché Raaga', 'ville' => 'Ouagadougou', 'cree_par' => $gestionnaire->id]);
    }

    /** Fichier « Locaux » de $n boutiques du site RAA (niveau R1, bloc A). */
    private function fichier(int $n, string $nom = 'locaux.xlsx'): UploadedFile
    {
        $classeur = new Spreadsheet;
        $feuille = $classeur->getActiveSheet()->setTitle('Locaux');
        $feuille->fromArray(['Code (calculé)', 'Site', 'Niveau', 'Bloc ou zone', 'Numéro', 'Type', 'Surface (m²)']);
        foreach (range(1, $n) as $i) {
            $feuille->fromArray([null, 'RAA', 'R1', 'A', $i, 'BOUT', 12], null, 'A'.($i + 1));
        }
        $chemin = tempnam(sys_get_temp_dir(), 'locaux').'.xlsx';
        (new Xlsx($classeur))->save($chemin);

        return new UploadedFile($chemin, $nom, null, null, true);
    }

    private function deposer(int $n, string $nom = 'locaux.xlsx')
    {
        return $this->post(route('admin.sites.boutiques-excel.deposer'), ['fichier' => $this->fichier($n, $nom)]);
    }

    private function pageImports()
    {
        return $this->get(route('admin.referencement.imports.entite', 'boutiques'))->assertOk();
    }

    public function test_bandeau_et_bouton_confirmer_en_haut_et_en_bas(): void
    {
        $this->deposer(3)->assertRedirect(route('admin.sites.boutiques-excel.apercu'));
        $page = $this->get(route('admin.sites.boutiques-excel.apercu'))->assertOk();

        $page->assertSee('class="adm-bandeau-apercu" role="status"', false)->assertSee(self::BANDEAU, false)
            ->assertSeeInOrder([self::BANDEAU, 'id="confirmer-import-haut-ouvrir"', '3 lignes valides', 'id="confirmer-import-ouvrir"'], false);
        $this->assertSame(0, Boutique::count());
    }

    public function test_import_en_attente_liste_puis_repris(): void
    {
        $this->deposer(4, 'locaux-raaga.xlsx');
        $attente = ImportEnAttente::query()->sole();
        $this->assertSame(ImportEnAttente::EN_ATTENTE, $attente->statut);

        // L'utilisateur quitte l'aperçu (nouvelle session) : l'import reste en attente, avec « Reprendre » et un badge.
        $this->flushSession();
        $this->connecterBackoffice(Role::GestionnaireSites);
        $page = $this->pageImports();
        $page->assertSeeInOrder(['En attente de confirmation', 'locaux-raaga.xlsx', 'En attente de confirmation', 'Reprendre', 'Historique des imports'])
            ->assertSee('href="'.route('admin.sites.boutiques-excel.reprendre', $attente).'"', false);
        $this->assertMatchesRegularExpression('#aria-controls="sous-menu-imports".*?<span class="adm-compteur">1<#s', $page->getContent());

        $this->get(route('admin.sites.boutiques-excel.reprendre', $attente))->assertRedirect(route('admin.sites.boutiques-excel.apercu'));
        $this->get(route('admin.sites.boutiques-excel.apercu'))->assertOk()->assertSee('4 lignes valides');
        $this->post(route('admin.sites.boutiques-excel.confirmer'))->assertSessionHas('succes');

        $this->assertSame(4, Boutique::count());
        $this->assertSame(ImportEnAttente::CONFIRME, $attente->fresh()->statut);
        $this->pageImports()->assertDontSee('Reprendre');
    }

    public function test_un_apercu_expire_au_bout_de_24_heures(): void
    {
        $this->deposer(2);
        $attente = ImportEnAttente::query()->sole();

        $this->travel(25)->hours();
        $this->connecterBackoffice(Role::GestionnaireSites); // Session back-office expirée entre-temps : nouvelle connexion.
        $this->pageImports()->assertSee('Expiré')->assertDontSee(route('admin.sites.boutiques-excel.reprendre', $attente), false);
        $this->assertSame(ImportEnAttente::EXPIRE, $attente->fresh()->statut);
        Storage::disk('local')->assertExists($attente->chemin);

        // Ni reprise ni confirmation : rien n'est enregistré.
        $this->get(route('admin.sites.boutiques-excel.reprendre', $attente))->assertRedirect(route('admin.referencement.imports.entite', 'boutiques'))
            ->assertSessionHas('erreur', 'Cet aperçu a expiré (plus de 24 heures) : rien n’a été enregistré. Importez de nouveau le fichier.');
        $this->post(route('admin.sites.boutiques-excel.confirmer'))->assertSessionHas('erreur');
        $this->assertSame(0, Boutique::count());
    }

    public function test_un_nouvel_import_annule_l_apercu_precedent(): void
    {
        $this->deposer(2, 'premier.xlsx');
        $premier = ImportEnAttente::query()->sole();
        $this->deposer(5, 'second.xlsx');
        $second = ImportEnAttente::query()->latest('id')->first();

        $this->assertSame(ImportEnAttente::ANNULE, $premier->fresh()->statut);
        $this->assertSame(ImportEnAttente::EN_ATTENTE, $second->statut);
        $this->assertSame(1, ImportEnAttente::query()->actifs()->count());
        $this->get(route('admin.sites.boutiques-excel.reprendre', $premier))->assertSessionHas('erreur');

        $this->get(route('admin.sites.boutiques-excel.reprendre', $second));
        $this->post(route('admin.sites.boutiques-excel.confirmer'))->assertSessionHas('succes');
        $this->assertSame(5, Boutique::count());
    }
}
