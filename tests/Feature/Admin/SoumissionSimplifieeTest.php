<?php

namespace Tests\Feature\Admin;

use App\Enums\Role;
use App\Enums\StatutSite;
use App\Enums\StatutTypeBoutique;
use App\Models\Site;
use App\Models\TypeBoutique;
use App\Models\User;
use App\Models\ZoneSite;
use Database\Seeders\NiveauxSeeder;
use Database\Seeders\ZonesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\Support\ActeursParametrage;
use Tests\TestCase;

/** Soumission simplifiée : « Enregistrer et soumettre », import avec soumission du site, soumission directe, « Mes brouillons ». */
class SoumissionSimplifieeTest extends TestCase
{
    use ActeursParametrage, RefreshDatabase;

    private User $gestionnaire;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([NiveauxSeeder::class, ZonesSeeder::class]);
        $this->creerActeurs();
        $this->gestionnaire = User::factory()->role(Role::GestionnaireSites)->create();
        $this->en($this->gestionnaire)->post(route('admin.sites.types.store'), ['code' => 'BOUT', 'libelle' => 'Petite boutique', 'attribuable' => 1]);
        $this->en($this->validateur)->post(route('admin.sites.types.confirmer', TypeBoutique::query()->where('code', 'BOUT')->sole()))->assertSessionHas('succes');
    }

    private function creerSite(string $code, bool $boutique, array $extra = []): Site
    {
        $this->en($this->gestionnaire)->post(route('admin.sites.store'), [...$this->champsSite($code), ...$extra]);
        $site = Site::query()->where('code', $code)->sole();
        if ($boutique) {
            $this->en($this->gestionnaire)->post(route('admin.referencement.boutiques.store', $site), [
                'niveau' => 'R1', 'bloc' => 'A', 'numero' => 1, 'surface' => 9, 'type_boutique_id' => TypeBoutique::query()->value('id'), 'etat_physique' => 'LIVREE',
            ]);
        }

        return $site->refresh();
    }

    private function champsSite(string $code): array
    {
        return ['code' => $code, 'nom' => 'Marché '.$code, 'region' => 'Centre', 'ville' => 'Ouagadougou'];
    }

    public function test_enregistrer_et_soumettre_en_une_action(): void
    {
        // Site prêt : enregistré et soumis par le même clic.
        $site = $this->creerSite('SAR', true);
        $this->en($this->gestionnaire)->put(route('admin.sites.update', $site), [...$this->champsSite('SAR'), 'nom' => 'Grand marché SAR', 'apres' => 'soumettre'])
            ->assertRedirect(route('admin.sites.show', $site))->assertSessionHas('succes', 'Enregistré et soumis au VALIDATEUR.');
        $this->assertSame(['Grand marché SAR', StatutSite::Soumis], [$site->refresh()->nom, $site->statut]);
        $this->en($this->validateur)->get(route('admin.a-valider.rubrique', 'sites'))->assertSee('SAR · Grand marché SAR');

        // Référentiel : « Enregistrer en brouillon », puis « Enregistrer et soumettre » depuis la fiche.
        $this->en($this->gestionnaire)->post(route('admin.referencement.zones.store', 'zones'), ['code' => 'KIO', 'libelle' => 'Kiosques', 'apres' => 'brouillon'])
            ->assertSessionHas('succes', 'Enregistré en brouillon.');
        $kio = ZoneSite::query()->where('code', 'KIO')->sole();
        $this->assertSame(StatutTypeBoutique::Brouillon, $kio->statut);
        $this->en($this->gestionnaire)->get(route('admin.referencement.zones.show', ['zones', $kio->id]))->assertSee('Enregistrer et soumettre')->assertSee('Enregistrer en brouillon');
        $this->en($this->gestionnaire)->put(route('admin.referencement.zones.update', ['zones', $kio->id]), ['libelle' => 'Kiosques couverts', 'apres' => 'soumettre'])
            ->assertSessionHas('succes', 'Enregistré et soumis au VALIDATEUR.');
        $this->assertSame(['Kiosques couverts', StatutTypeBoutique::AValider], [$kio->refresh()->libelle, $kio->statut]);
    }

    public function test_brouillon_conserve_si_le_controle_echoue(): void
    {
        // Site sans boutique : enregistré en brouillon, formulaire rouvert avec la raison au-dessus des boutons.
        $page = $this->en($this->gestionnaire)->followingRedirects()->post(route('admin.sites.store'), [...$this->champsSite('A04'), 'apres' => 'soumettre'])->assertOk();
        $site = Site::query()->where('code', 'A04')->sole();
        $this->assertSame(StatutSite::Brouillon, $site->statut);
        $page->assertSee('action="'.route('admin.sites.update', $site).'"', false)
            ->assertSee('Enregistré en brouillon : la soumission n’a pas abouti (raison ci-dessous).')
            ->assertSee('data-erreur-soumission', false)->assertSee('Ajoutez au moins une boutique avant de soumettre le site.');
    }

    public function test_import_avec_soumission_du_site(): void
    {
        $site = $this->creerSite('SAR', false);
        $classeur = new Spreadsheet;
        $classeur->getActiveSheet()->setTitle('Locaux')->fromArray([
            ['Code (calculé)', 'Site', 'Niveau', 'Bloc ou zone', 'Numéro', 'Type', 'Surface (m²)'],
            [null, 'SAR', 'R1', 'A', 1, 'BOUT', 9],
            [null, 'SAR', 'R1', 'A', 2, 'BOUT', 12],
        ]);
        $chemin = tempnam(sys_get_temp_dir(), 'ref').'.xlsx';
        (new Xlsx($classeur))->save($chemin);
        $this->en($this->gestionnaire)->post(route('admin.referencement.boutiques.excel.deposer'), ['site_id' => $site->id, 'fichier' => new UploadedFile($chemin, 'locaux.xlsx', null, null, true)]);

        // Case cochée par défaut dans la fenêtre « Confirmer l'import ».
        $this->en($this->gestionnaire)->get(route('admin.referencement.boutiques.excel.import', $site))->assertOk()
            ->assertSee('name="statut_apres" value="SOUMETTRE"', false)->assertSee('Statut après l’import');

        $this->en($this->gestionnaire)->post(route('admin.referencement.boutiques.excel.import.confirmer', $site), ['statut_apres' => 'SOUMETTRE'])
            ->assertSessionHas('succes', fn (string $m) => str_contains($m, '1 site soumis.'));
        $this->assertSame(2, $site->boutiques()->count());
        $this->assertSame(StatutSite::Soumis, $site->refresh()->statut);
    }

    public function test_soumission_groupee_sans_fenetre_quand_tout_est_pret(): void
    {
        $cles = collect(['SAR', 'RAA'])->map(fn ($code) => 'site:'.$this->creerSite($code, true)->id)->all();

        $this->en($this->gestionnaire)->get(route('admin.sites'))->assertSee('x-ref="formulaireSoumettre"', false);
        $controle = $this->en($this->gestionnaire)->postJson(route('admin.soumission.controle'), ['elements' => $cles])->json('elements');
        $this->assertTrue(collect($controle)->every('pret'), 'tout est prêt : la fenêtre ne s’ouvre pas');

        $this->en($this->gestionnaire)->post(route('admin.soumission.soumettre'), ['elements' => $cles])->assertSessionHas('succes', '2 éléments soumis au VALIDATEUR.');
        $this->assertSame(2, Site::query()->where('statut', StatutSite::Soumis)->count());
    }

    public function test_tout_soumettre_depuis_mes_brouillons(): void
    {
        $sar = $this->creerSite('SAR', true);
        $a04 = $this->creerSite('A04', false);
        $raa = $this->creerSite('RAA', true);
        $this->en($this->gestionnaire)->post(route('admin.sites.soumettre', $raa))->assertSessionHas('succes');
        $this->en($this->validateur)->post(route('admin.sites.rejeter', $raa), ['motif' => 'Adresse à préciser'])->assertSessionHas('succes');
        $this->en($this->gestionnaire)->post(route('admin.referencement.zones.store', 'zones'), ['code' => 'KIO', 'libelle' => 'Kiosques', 'apres' => 'brouillon']);

        // Tableau de bord et badge du menu : 4 brouillons non soumis.
        $this->en($this->gestionnaire)->get(route('admin.tableau'))->assertSee('data-brouillons', false)->assertSee('4 brouillons non soumis')
            ->assertSee('href="'.route('admin.brouillons').'"', false);

        $page = $this->en($this->gestionnaire)->get(route('admin.brouillons'))->assertOk();
        $page->assertSee('data-groupe="site"', false)->assertSee('data-groupe="zones"', false)->assertSee('Adresse à préciser')
            ->assertSee('Tout soumettre (4)')->assertSee('RAA · Marché RAA')->assertSee('KIO · Kiosques');

        $cles = ['site:'.$a04->id, 'site:'.$raa->id, 'site:'.$sar->id, 'zones:'.ZoneSite::query()->where('code', 'KIO')->value('id')];
        $this->from(route('admin.brouillons'))->en($this->gestionnaire)->post(route('admin.soumission.soumettre'), ['elements' => $cles])
            ->assertRedirect(route('admin.brouillons'))
            ->assertSessionHas('succes', '3 soumis, 1 refusé : A04, ajoutez au moins une boutique avant de soumettre le site.');
        $this->assertSame(StatutSite::Soumis, $raa->refresh()->statut);
        $this->en($this->gestionnaire)->get(route('admin.brouillons'))->assertSee('Tout soumettre (1)')->assertSee('A04 · Marché A04');

        // Le VALIDATEUR et le SUPER_ADMIN n'ont pas cette page.
        $this->en($this->validateur)->get(route('admin.brouillons'))->assertForbidden();
        $this->en($this->superAdmin)->get(route('admin.brouillons'))->assertForbidden();
    }
}
