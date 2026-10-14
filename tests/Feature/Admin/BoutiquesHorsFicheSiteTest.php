<?php

namespace Tests\Feature\Admin;

use App\Enums\Role;
use App\Enums\StatutBoutique;
use App\Enums\StatutSite;
use App\Models\Boutique;
use App\Models\Site;
use App\Models\TypeBoutique;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\Support\ActeursParametrage;
use Tests\TestCase;

/** Les boutiques se gèrent dans l'onglet Boutiques du Référencement, après choix du site ; la fiche site n'en montre aucune. */
class BoutiquesHorsFicheSiteTest extends TestCase
{
    use ActeursParametrage, RefreshDatabase;

    private User $gestionnaire;

    private TypeBoutique $type;

    private Site $brouillon;

    private Site $confirme;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        $this->creerActeurs();
        $this->gestionnaire = User::factory()->role(Role::GestionnaireSites)->create();
        $this->type = TypeBoutique::create(['code' => 'BOUT', 'libelle' => 'Boutique']);
        $site = fn (string $code, string $nom, StatutSite $statut) => Site::create(['statut' => $statut, 'code' => $code, 'nom' => $nom, 'region' => 'Centre',
            'commune' => 'Ouagadougou', 'adresse' => 'Avenue de la Nation', 'version' => 1, 'cree_par' => $this->gestionnaire->id]);
        $this->brouillon = $site('RAA', 'Marché Raaga', StatutSite::Brouillon);
        $this->confirme = $site('SAR', 'Marché de Sarfalao', StatutSite::Confirme);
        $this->confirme->boutiques()->create(['niveau' => 'R1', 'bloc' => 'A', 'numero' => '001', 'surface' => 9,
            'type_boutique_id' => $this->type->id, 'statut' => StatutBoutique::Disponible]);
    }

    private function fichier(array $lignes): UploadedFile
    {
        $classeur = new Spreadsheet;
        $classeur->getActiveSheet()->setTitle('Locaux')
            ->fromArray(['Code (calculé)', 'Site', 'Niveau', 'Bloc ou zone', 'Numéro', 'Type', 'Surface (m²)', 'Planche', 'Observations', 'Contrôle']);
        foreach ($lignes as $i => $ligne) {
            $classeur->getActiveSheet()->fromArray($ligne, null, 'B'.($i + 2));
        }
        $chemin = tempnam(sys_get_temp_dir(), 'locaux').'.xlsx';
        (new Xlsx($classeur))->save($chemin);

        return new UploadedFile($chemin, 'locaux.xlsx', null, null, true);
    }

    public function test_la_fiche_site_n_affiche_plus_aucune_boutique(): void
    {
        $this->en($this->gestionnaire)->get(route('admin.sites.show', $this->confirme))->assertOk()
            ->assertSee('Marché de Sarfalao')->assertDontSee('SAR-R1-A-001')->assertDontSee('Boutiques du site')->assertDontSee('Fichier Excel');
        $this->en($this->gestionnaire)->get(route('admin.referencement.boutiques', ['site' => $this->confirme->id]))->assertOk()->assertSee('SAR-R1-A-001');
    }

    public function test_ajout_depuis_l_onglet_boutiques_apres_choix_du_site(): void
    {
        // Fenêtre « Ajouter des boutiques » : seuls les sites en brouillon ou en révision sont proposés.
        $page = $this->en($this->gestionnaire)->get(route('admin.referencement.boutiques'))->assertOk()->getContent();
        preg_match('#<select id="ajout-site".*?</select>#s', $page, $choix);
        $this->assertStringContainsString('RAA · Marché Raaga', $choix[0]);
        $this->assertStringNotContainsString('Sarfalao', $choix[0]);
        $this->assertStringContainsString('<dialog id="ajouter-boutiques"', $page);
        // L'ancien écran d'ajout ouvre la fenêtre sur la liste.
        $this->en($this->gestionnaire)->get(route('admin.referencement.boutiques.ajouter.ecran', ['site' => $this->brouillon->id]))
            ->assertRedirect(route('admin.referencement.boutiques', ['ajouter' => 1, 'site' => $this->brouillon->id]));

        $this->en($this->gestionnaire)->post(route('admin.referencement.boutiques.store', $this->brouillon), [
            'niveau' => 'R1', 'bloc' => 'A', 'numero' => 45, 'type_boutique_id' => $this->type->id, 'etat_physique' => 'LIVREE',
        ])->assertRedirect(route('admin.referencement.boutiques', ['site' => $this->brouillon->id]))->assertSessionHas('succes');
        $this->en($this->gestionnaire)->post(route('admin.referencement.boutiques.serie', $this->brouillon), [
            'niveau' => 'RC', 'bloc' => 'B', 'debut' => 1, 'fin' => 3, 'type_boutique_id' => $this->type->id, 'etat_physique' => 'LIVREE',
        ])->assertSessionHas('succes');
        $this->assertSame(['RAA-R1-A-045', 'RAA-RC-B-001', 'RAA-RC-B-002', 'RAA-RC-B-003'], $this->brouillon->boutiques()->orderBy('code')->pluck('code')->all());

        // Site confirmé : ajout refusé, comme avant.
        $this->en($this->gestionnaire)->post(route('admin.referencement.boutiques.store', $this->confirme), [
            'niveau' => 'R1', 'bloc' => 'A', 'numero' => 2, 'type_boutique_id' => $this->type->id, 'etat_physique' => 'LIVREE',
        ])->assertSessionHas('erreur');
        $this->assertSame(1, $this->confirme->boutiques()->count());
    }

    public function test_import_depuis_l_onglet_boutiques_pour_le_site_choisi(): void
    {
        // Le choix du site pour l'import ne propose pas le site confirmé ; l'export, si.
        $page = $this->en($this->gestionnaire)->get(route('admin.referencement.boutiques'))->assertOk()->getContent();
        preg_match('#<select id="import-site".*?</select>#s', $page, $import);
        $this->assertStringContainsString('RAA · Marché Raaga', $import[0]);
        $this->assertStringNotContainsString('Sarfalao', $import[0]);
        $this->assertStringContainsString('formaction="'.route('admin.referencement.boutiques.excel.export').'"', $page);

        // Colonne Site vide : code du site choisi ; code d'un autre site : erreur.
        $this->en($this->gestionnaire)->post(route('admin.referencement.boutiques.excel.deposer'), [
            'site_id' => $this->brouillon->id, 'fichier' => $this->fichier([[null, 'R1', 'A', 1, 'BOUT', 9], ['SAR', 'R1', 'A', 2, 'BOUT', 9]]),
        ])->assertRedirect(route('admin.referencement.boutiques.excel.import', $this->brouillon));
        $this->en($this->gestionnaire)->get(route('admin.referencement.boutiques.excel.import', $this->brouillon))->assertOk()
            ->assertSee('le code du site choisi (RAA) lui est appliqué.')->assertSee('1 ligne valide');
        $this->en($this->gestionnaire)->post(route('admin.referencement.boutiques.excel.import.annuler', $this->brouillon))
            ->assertRedirect(route('admin.referencement.boutiques', ['site' => $this->brouillon->id]));

        $this->en($this->gestionnaire)->post(route('admin.referencement.boutiques.excel.deposer'), [
            'site_id' => $this->brouillon->id, 'fichier' => $this->fichier([[null, 'R1', 'A', 1, 'BOUT', 9]]),
        ]);
        $this->en($this->gestionnaire)->post(route('admin.referencement.boutiques.excel.import.confirmer', $this->brouillon))->assertSessionHas('succes');
        $this->assertSame(['RAA-R1-A-001'], $this->brouillon->boutiques()->pluck('code')->all());

        // Site confirmé : modèle et import refusés ; export permis.
        $this->en($this->gestionnaire)->post(route('admin.referencement.boutiques.excel.deposer'), [
            'site_id' => $this->confirme->id, 'fichier' => $this->fichier([[null, 'R1', 'A', 5, 'BOUT', 9]]),
        ])->assertRedirect(route('admin.referencement.boutiques', ['site' => $this->confirme->id]))->assertSessionHas('erreur');
        $this->en($this->gestionnaire)->get(route('admin.referencement.boutiques.excel.modele', ['site_id' => $this->confirme->id]))->assertSessionHas('erreur');
        $this->en($this->gestionnaire)->get(route('admin.referencement.boutiques.excel.export', ['site_id' => $this->confirme->id]))->assertOk();
        $this->assertSame(1, $this->confirme->boutiques()->count());
    }

    public function test_boutons_icones_voir_modifier_supprimer_dans_les_listes(): void
    {
        $libre = $this->brouillon->boutiques()->create(['niveau' => 'R1', 'bloc' => 'A', 'numero' => '007', 'surface' => 9,
            'type_boutique_id' => $this->type->id, 'statut' => StatutBoutique::Disponible]);
        $page = $this->en($this->gestionnaire)->get(route('admin.referencement.boutiques'))->assertOk();
        // Liste comme la maquette : « Voir » ; « Modifier » seulement si la boutique est libre ; suppression depuis la fiche.
        $page->assertSee(route('admin.referencement.boutiques.show', $libre))->assertSee(route('admin.referencement.boutiques.edit', $libre))
            ->assertSee('aria-label="Voir RAA-R1-A-007"', false)->assertSee('aria-label="Modifier RAA-R1-A-007"', false);
        $figee = $this->confirme->boutiques()->sole();
        $page->assertSee(route('admin.referencement.boutiques.show', $figee))->assertDontSee(route('admin.referencement.boutiques.edit', $figee));
        $this->en($this->gestionnaire)->get(route('admin.referencement.boutiques.show', $libre))->assertSee(route('admin.referencement.boutiques.destroy', $libre));

        $this->en($this->gestionnaire)->delete(route('admin.referencement.boutiques.destroy', $libre))->assertSessionHas('succes');
        $this->assertSoftDeleted($libre);
        $this->en($this->gestionnaire)->delete(route('admin.referencement.boutiques.destroy', $figee))->assertSessionHas('erreur');

        // Sites : voir pour tous, modifier pour le GESTIONNAIRE sur un site en brouillon.
        $this->en($this->gestionnaire)->get(route('admin.sites'))->assertOk()
            ->assertSee(route('admin.sites.show', $this->confirme))->assertSee(route('admin.referencement.capacites', ['site' => $this->confirme->id]))
            ->assertSee('aria-label="Capacités prévues Marché de Sarfalao"', false)->assertSee(route('admin.sites.edit', $this->brouillon))->assertDontSee(route('admin.sites.edit', $this->confirme));
        $this->en($this->validateur)->get(route('admin.referencement.boutiques'))->assertOk()
            ->assertSee('aria-label="Voir SAR-R1-A-001"', false)->assertDontSee('aria-label="Modifier', false);
    }

    public function test_les_anciennes_adresses_des_boutiques_redirigent_vers_l_onglet_filtre(): void
    {
        $id = $this->brouillon->id;
        $boutique = Boutique::query()->firstOrFail();
        foreach (["/admin/sites/{$id}/boutiques/{$boutique->id}/modifier", "/admin/sites/{$id}/excel/import", "/admin/sites/{$id}/excel/modele",
            "/admin/referencement/sites/{$id}/boutiques/{$boutique->id}/modifier", "/admin/referencement/sites/{$id}/excel/export"] as $ancienne) {
            $this->en($this->gestionnaire)->get($ancienne)->assertStatus(301)->assertRedirect(url('/admin/referencement/boutiques?site='.$id));
        }
    }
}
