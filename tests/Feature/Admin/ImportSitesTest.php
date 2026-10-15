<?php

namespace Tests\Feature\Admin;

use App\Enums\PhaseSite;
use App\Enums\Role;
use App\Enums\StatutBoutique;
use App\Enums\StatutSite;
use App\Models\AuditLog;
use App\Models\CapaciteSite;
use App\Models\Site;
use App\Models\TypeBoutique;
use App\Models\User;
use Database\Seeders\ReferentielsSitesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\Support\ActeursParametrage;
use Tests\TestCase;

class ImportSitesTest extends TestCase
{
    use ActeursParametrage, RefreshDatabase;

    /** En-têtes du fichier de référence « Raaga_import_sites.xlsx », ligne 1. */
    private const ENTETES_SITES = ['Code site', 'Nom officiel', 'Ville', 'Arrondissement', 'Adresse', 'Latitude', 'Longitude', "Maître d'ouvrage", "Maître d'œuvre",
        'Phase', 'Livraison prévue', 'Référence des plans', 'Version des plans', 'Source des données', 'Contrôle'];

    private const ENTETES_CAPACITES = ['Code site', 'Niveau', 'Zone', 'Type de local', 'Nombre prévu', 'Surface unitaire (m²)', 'Surface totale saisie (m²)',
        'Surface retenue (m²)', 'Source', 'Observations', 'Contrôle'];

    private User $gestionnaire;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        $this->creerActeurs();
        $this->seed(ReferentielsSitesSeeder::class);
        $this->gestionnaire = User::factory()->role(Role::GestionnaireSites)->create();
    }

    /**
     * Classeur au format de référence : sites [code, nom, ville, arrondissement, phase] et capacités
     * [site, niveau, zone, type, nombre, surface unitaire, surface totale] (les colonnes calculées restent vides).
     */
    private function fichier(array $sites, array $capacites, ?Spreadsheet $classeur = null): UploadedFile
    {
        if ($classeur === null) {
            $classeur = new Spreadsheet;
            $classeur->getActiveSheet()->setTitle('Sites')->fromArray(self::ENTETES_SITES);
            $classeur->createSheet()->setTitle('Capacités')->fromArray(self::ENTETES_CAPACITES);
        }
        foreach ($sites as $i => [$code, $nom, $ville, $arrondissement, $phase]) {
            $classeur->getSheetByName('Sites')->fromArray([$code, $nom, $ville, $arrondissement], null, 'A'.($i + 2));
            $classeur->getSheetByName('Sites')->setCellValue('J'.($i + 2), $phase);
        }
        foreach ($capacites as $i => $ligne) {
            $classeur->getSheetByName('Capacités')->fromArray($ligne, null, 'A'.($i + 2));
        }
        $chemin = tempnam(sys_get_temp_dir(), 'sites').'.xlsx';
        (new Xlsx($classeur))->save($chemin);

        return new UploadedFile($chemin, 'Raaga_import_sites.xlsx', null, null, true);
    }

    private function deposer(UploadedFile $fichier): TestResponse
    {
        return $this->en($this->gestionnaire)->post(route('admin.sites.excel.deposer'), ['fichier_sites' => $fichier]);
    }

    private function site(string $code, StatutSite $statut): Site
    {
        return Site::create(['statut' => $statut, 'code' => $code, 'nom' => 'Ancien nom', 'ville' => 'Ouagadougou', 'version' => 1, 'cree_par' => $this->gestionnaire->id]);
    }

    public function test_import_valide_a_partir_du_modele(): void
    {
        // Le modèle reprend exactement les onglets Sites et Capacités du fichier de référence.
        $modele = tempnam(sys_get_temp_dir(), 'modele');
        file_put_contents($modele, $this->en($this->gestionnaire)->get(route('admin.sites.excel.modele'))->assertOk()->streamedContent());
        $classeur = IOFactory::load($modele);
        $this->assertSame(['Sites', 'Capacités'], $classeur->getSheetNames());
        $this->assertSame([self::ENTETES_SITES], $classeur->getSheetByName('Sites')->rangeToArray('A1:O1'));
        $this->assertSame([self::ENTETES_CAPACITES], $classeur->getSheetByName('Capacités')->rangeToArray('A1:K1'));

        // SAR existe en brouillon : il est mis à jour ; RAA est créé.
        $sar = $this->site('SAR', StatutSite::Brouillon);
        $fichier = $this->fichier([
            ['RAA', "Marché moderne de l'Arrondissement N°1 (Raaga)", 'Ouagadougou', '1', 'Études', 16245000000, 5824000000],
            ['sar', 'Marché moderne de Sarfalao', 'Bobo-Dioulasso', null, 'Avant-projet sommaire (APS)', 21359000000, 7140000000],
        ], [
            ['RAA', 'R1', null, 'BOUT', 214, 8.6, null],
            ['RAA', 'RC', 'BP', 'PV', null, null, 450],
            ['RAA', null, 'MAG', 'MAG', 4, null, null],
            ['SAR', 'SS', null, 'BOUT', null, null, 6510],
        ], $classeur);
        $this->deposer($fichier)->assertRedirect(route('admin.sites.excel.apercu'));

        // Aperçu : rien n'est enregistré.
        $this->en($this->gestionnaire)->get(route('admin.sites.excel.apercu'))->assertOk()
            ->assertSee('Sites à créer (en brouillon) (1)')->assertSee('Sites à mettre à jour (1)')->assertSee('Capacités prévues (4)');
        $this->assertSame(1, Site::query()->count());

        $this->en($this->gestionnaire)->post(route('admin.sites.excel.confirmer'))->assertSessionHas('succes');
        $raa = Site::query()->where('code', 'RAA')->sole();
        $this->assertSame([StatutSite::Brouillon, 'Ouagadougou', '1', PhaseSite::Etudes], [$raa->statut, $raa->ville, $raa->arrondissement, $raa->phase]);
        $this->assertSame(['Marché moderne de Sarfalao', 'Bobo-Dioulasso', PhaseSite::Aps], [$sar->fresh()->nom, $sar->fresh()->ville, $sar->fresh()->phase]);
        $this->assertSame(3, $raa->capacites()->count());
        $this->assertEqualsWithDelta(1840.4, $raa->capacites()->where('niveau', 'R1')->sole()->surfaceRetenue(), 0.001);

        // Journal : auteur, fichier, empreinte, nombres ; fichier conservé hors du dossier public.
        $journal = AuditLog::query()->where('action', 'sites.importes')->sole();
        $this->assertSame($this->gestionnaire->id, $journal->acteur_id);
        $this->assertSame(['fichier' => 'Raaga_import_sites.xlsx', 'empreinte' => hash_file('sha256', $fichier->getPathname()),
            'sites_crees' => 1, 'sites_mis_a_jour' => 1, 'sites_inchanges' => 0, 'capacites' => 4, 'statut_apres' => 'BROUILLON'],
            collect($journal->nouvelle_valeur)->except(['chemin', 'import_id'])->all());
        $this->assertSame(Storage::disk('local')->allFiles('imports/sites'), [$journal->nouvelle_valeur['chemin']]);

        // Fiche : capacité prévue comparée au référentiel (aucune boutique encore : écarts signalés).
        $bout = TypeBoutique::query()->where('code', 'BOUT')->value('id');
        $raa->boutiques()->create(['niveau' => 'R1', 'bloc' => 'A', 'numero' => '001', 'surface' => 8.6, 'type_boutique_id' => $bout, 'statut' => StatutBoutique::Disponible]);
        $this->en($this->validateur)->get(route('admin.sites.show', $raa))->assertOk()
            ->assertSee('Capacité prévue / référentiel')->assertSee('Nombre : -213');
    }

    public function test_un_fichier_avec_erreurs_est_refuse_sans_rien_ecrire(): void
    {
        $this->deposer($this->fichier([
            ['RA', 'Site au code trop court', 'Ouagadougou', null, 'Études', null, null],
            ['RAB', 'Sans ville', null, null, 'Démolition', 1000, 2000],
        ], [
            ['XXX', 'R1', null, 'BOUT', 10, 8.6, null],
            ['RAB', 'R9', 'ZZ', 'INCONNU', -3, null, null],
        ]));

        $this->en($this->gestionnaire)->get(route('admin.sites.excel.apercu'))->assertOk()
            ->assertSee('Le format de Code site n’est pas valide.', false)->assertSee('Le champ « Ville » est obligatoire', false)
            ->assertSee('Valeur non autorisée (valeurs possibles : Études')
            ->assertSee('Site XXX absent de l’onglet « Sites » et de la plateforme.', false)->assertSee('Type « INCONNU » inconnu', false)
            ->assertSee('Capacités');

        $this->en($this->gestionnaire)->post(route('admin.sites.excel.confirmer'))->assertSessionHas('erreur');
        $this->assertSame(0, Site::query()->count());
        $this->assertSame(0, CapaciteSite::query()->count());
        $this->assertFalse(AuditLog::query()->where('action', 'sites.importes')->exists());
    }

    public function test_un_site_confirme_n_est_pas_modifie(): void
    {
        $raa = $this->site('RAA', StatutSite::Confirme);
        $soumis = $this->site('SAR', StatutSite::Soumis);

        $this->deposer($this->fichier([['RAA', 'Nouveau nom', 'Ouagadougou', null, null, null, null]], [['SAR', 'R1', null, 'BOUT', 10, 8.6, null]]));
        $this->en($this->gestionnaire)->get(route('admin.sites.excel.apercu'))->assertOk()
            ->assertSee('Le site RAA est confirmé : il ne peut pas être modifié par un import')
            ->assertSee('Le site SAR est soumis à confirmation : il ne peut pas être modifié par un import');
        $this->en($this->gestionnaire)->post(route('admin.sites.excel.confirmer'))->assertSessionHas('erreur');

        $this->assertSame(['Ancien nom', 'RAA', StatutSite::Confirme], [$raa->fresh()->nom, $raa->fresh()->code, $raa->fresh()->statut]);
        $this->assertSame(0, $soumis->capacites()->count());
    }

    public function test_les_doublons_bloquent_l_import(): void
    {
        $this->deposer($this->fichier([
            ['RAA', 'Marché Raaga', 'Ouagadougou', null, null, null, null],
            ['raa', 'Marché Raaga bis', 'Ouagadougou', null, null, null, null],
        ], [
            ['RAA', 'RC', 'BP', 'PV', null, null, 450],
            ['RAA', 'RC', 'BP', 'PV', null, null, 300],
        ]));

        $this->en($this->gestionnaire)->get(route('admin.sites.excel.apercu'))->assertOk()
            ->assertSee('Doublon : le site RAA figure déjà ligne 2.')->assertSee('Doublon : même site, niveau, zone et type que la ligne 2.');
        $this->en($this->gestionnaire)->post(route('admin.sites.excel.confirmer'))->assertSessionHas('erreur');
        $this->assertSame(0, Site::query()->count());
    }

    public function test_les_autres_profils_n_ont_pas_acces(): void
    {
        foreach ([$this->validateur, $this->regulateur, User::factory()->role(Role::SuperAdmin)->create(), User::factory()->role(Role::Admin)->create()] as $profil) {
            $this->en($profil)->get(route('admin.sites.excel.modele'))->assertForbidden();
            $this->en($profil)->get(route('admin.sites.excel.export'))->assertForbidden();
            $this->en($profil)->post(route('admin.sites.excel.deposer'), ['fichier_sites' => $this->fichier([['RAA', 'Marché', 'Ouaga', null, null, null, null]], [])])->assertForbidden();
            $this->en($profil)->get(route('admin.sites.excel.apercu'))->assertForbidden();
            $this->en($profil)->post(route('admin.sites.excel.confirmer'))->assertForbidden();
        }
        $this->assertSame(0, Site::query()->count());
        $this->assertSame([], Storage::disk('local')->allFiles());
    }
}
