<?php

namespace Tests\Feature\Admin;

use App\Enums\Role;
use App\Enums\StatutBoutique;
use App\Enums\StatutSite;
use App\Models\Boutique;
use App\Models\HistoriqueCode;
use App\Models\Site;
use App\Models\TypeBoutique;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\Support\ActeursParametrage;
use Tests\TestCase;

/** Règles de correspondance du code site à l'import des boutiques (depuis la fiche d'un site et combiné). */
class CorrespondanceCodeSiteTest extends TestCase
{
    use ActeursParametrage, RefreshDatabase;

    private User $gestionnaire;

    private Site $raa;

    private Site $sar;

    private int $type;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        $this->creerActeurs();
        $this->gestionnaire = User::factory()->role(Role::GestionnaireSites)->create();
        $this->type = TypeBoutique::create(['code' => 'BOUT', 'libelle' => 'Boutique'])->id;
        foreach (['raa' => 'RAA', 'sar' => 'SAR'] as $propriete => $code) {
            $this->{$propriete} = Site::create(['statut' => StatutSite::Brouillon, 'code' => $code, 'nom' => 'Marché '.$code, 'ville' => 'Ouagadougou',
                'cree_par' => $this->gestionnaire->id]);
        }
    }

    /** Lignes [site, niveau, bloc, numéro, (code lu)] dans l'onglet « Locaux ». */
    private function fichier(array $lignes): UploadedFile
    {
        $classeur = new Spreadsheet;
        $feuille = $classeur->getActiveSheet()->setTitle('Locaux');
        $feuille->fromArray(['Code (calculé)', 'Site', 'Niveau', 'Bloc ou zone', 'Numéro', 'Type', 'Surface (m²)', 'Planche', 'Observations', 'Contrôle']);
        foreach ($lignes as $i => [$site, $niveau, $bloc, $numero, $code]) {
            $feuille->fromArray([$code, $site, $niveau, $bloc, $numero, 'BOUT', 9], null, 'A'.($i + 2));
        }
        $chemin = tempnam(sys_get_temp_dir(), 'locaux').'.xlsx';
        (new Xlsx($classeur))->save($chemin);

        return new UploadedFile($chemin, 'locaux.xlsx', null, null, true);
    }

    /** Import pour un site choisi ($site) ou combiné (null) : dépôt puis aperçu. */
    private function apercu(?Site $site, array $lignes): TestResponse
    {
        $this->en($this->gestionnaire)->post($site ? route('admin.referencement.boutiques.excel.deposer') : route('admin.sites.boutiques-excel.deposer'),
            ['site_id' => $site?->id, 'fichier' => $this->fichier($lignes)]);

        return $this->en($this->gestionnaire)->get($site ? route('admin.referencement.boutiques.excel.import', $site) : route('admin.sites.boutiques-excel.apercu'))->assertOk();
    }

    private function confirmer(?Site $site): TestResponse
    {
        return $this->en($this->gestionnaire)->post($site ? route('admin.referencement.boutiques.excel.import.confirmer', $site) : route('admin.sites.boutiques-excel.confirmer'));
    }

    public function test_regle_1_le_code_site_est_normalise(): void
    {
        $this->apercu(null, [[' r a a ', 'R1', 'A', 1, null], ["s a r\u{00A0}", 'R1', 'A', 1, null]])->assertSee('2 lignes valides');
        $this->confirmer(null)->assertSessionHas('succes');
        $this->assertSame(['RAA-R1-A-001', 'SAR-R1-A-001'], Boutique::query()->orderBy('code')->pluck('code')->all());
    }

    public function test_regle_2_site_vide_pour_le_site_choisi(): void
    {
        $this->apercu($this->raa, [[null, 'R1', 'A', 1, null], ['', 'R1', 'A', 2, null], ['RAA', 'R1', 'A', 3, null]])
            ->assertSee('2 lignes sans code site : le code du site choisi (RAA) leur est appliqué.');
        // Un avertissement n'empêche pas la confirmation.
        $this->confirmer($this->raa)->assertSessionHas('succes');
        $this->assertSame(3, $this->raa->boutiques()->count());

        // Dans l'import combiné, le site reste obligatoire.
        $this->apercu(null, [[null, 'R1', 'A', 9, null]])->assertSee('Valeur obligatoire.');
    }

    public function test_regle_3_code_d_un_autre_site(): void
    {
        $this->apercu($this->raa, [['RAA', 'R1', 'A', 1, null], ['SAR', 'R1', 'A', 2, null]])
            ->assertSee('Ligne 3 : site SAR, alors que vous importez dans RAA.');
        $this->confirmer($this->raa)->assertSessionHas('erreur');
        $this->assertSame(0, Boutique::query()->count());
    }

    public function test_regle_4_code_inconnu_avec_suggestion(): void
    {
        $this->apercu(null, [['RAB', 'R1', 'A', 1, null], ['ZZZ', 'R1', 'A', 2, null]])
            ->assertSee('Ligne 2 : site RAB inconnu. Vouliez-vous dire RAA ?')
            ->assertSee('Ligne 3 : site ZZZ inconnu.')->assertDontSee('Ligne 3 : site ZZZ inconnu. Vouliez-vous dire');
        $this->confirmer(null)->assertSessionHas('erreur');
    }

    public function test_regle_5_site_confirme_import_entierement_refuse(): void
    {
        $this->raa->forceFill(['statut' => StatutSite::Confirme])->save();

        $this->apercu(null, [['SAR', 'R1', 'A', 1, null], ['RAA', 'R1', 'A', 1, null]])
            ->assertSee('Le site RAA est confirmé : l’import est refusé. Remettez-le d’abord en révision', false);
        $this->confirmer(null)->assertSessionHas('erreur');
        $this->assertSame(0, Boutique::query()->count()); // Rien pour SAR non plus : tout ou rien.

        // Site confirmé choisi : refusé dès le dépôt, avec le même message.
        $this->en($this->gestionnaire)->post(route('admin.referencement.boutiques.excel.deposer'), ['site_id' => $this->raa->id, 'fichier' => $this->fichier([['RAA', 'R1', 'A', 1, null]])])
            ->assertSessionHas('erreur', fn ($m) => str_contains($m, 'Remettez-le d’abord en révision'));
    }

    public function test_regle_6_changement_de_code_recalcule_les_boutiques_et_l_ancien_code_est_refuse(): void
    {
        $boutique = $this->raa->boutiques()->create(['niveau' => 'R1', 'bloc' => 'A', 'numero' => '001', 'surface' => 9, 'type_boutique_id' => $this->type, 'statut' => StatutBoutique::Disponible]);
        $this->assertSame('RAA-R1-A-001', $boutique->code);

        $this->en($this->gestionnaire)->put(route('admin.sites.update', $this->raa), ['code' => 'RAX', 'nom' => 'Marché RAA', 'ville' => 'Ouagadougou'])
            ->assertSessionHas('succes');
        $this->assertSame('RAX-R1-A-001', $boutique->fresh()->code);
        $this->assertSame([['site', 'RAA', 'RAX'], ['boutique', 'RAA-R1-A-001', 'RAX-R1-A-001']],
            HistoriqueCode::query()->orderBy('id')->get()->map(fn ($h) => [$h->objet_type, $h->ancien_code, $h->nouveau_code])->all());

        // Un fichier portant l'ancien code est refusé.
        $this->apercu(null, [['RAA', 'R1', 'A', 2, null]])->assertSee('Ligne 2 : site RAA, mais ce site s’appelle désormais RAX.', false);
        $this->apercu($this->raa->fresh(), [['RAA', 'R1', 'A', 2, null]])->assertSee('ce site s’appelle désormais RAX', false);
        $this->confirmer(null)->assertSessionHas('erreur');
        $this->assertSame(1, Boutique::query()->count());
    }

    public function test_regle_7_le_code_recalcule_l_emporte_sur_la_colonne_code(): void
    {
        $this->apercu($this->raa, [['RAA', 'R1', 'A', 1, 'RAA-R1-A-999'], ['RAA', 'R1', 'A', 2, 'raa-r1-a-002']])
            ->assertSee('Ligne 2 : la colonne Code indique RAA-R1-A-999 ; le code calculé RAA-R1-A-001 est retenu.')
            ->assertDontSee('Ligne 3 : la colonne Code');
        $this->confirmer($this->raa)->assertSessionHas('succes');
        $this->assertSame(['RAA-R1-A-001', 'RAA-R1-A-002'], Boutique::query()->orderBy('code')->pluck('code')->all());
    }

    public function test_regle_8_un_import_de_boutiques_ne_cree_jamais_de_site(): void
    {
        $this->apercu(null, [['NEW', 'R1', 'A', 1, null], ['SAR', 'R1', 'A', 1, null]])->assertSee('Ligne 2 : site NEW inconnu.');
        $this->confirmer(null)->assertSessionHas('erreur');
        $this->assertSame(['RAA', 'SAR'], Site::query()->orderBy('code')->pluck('code')->all());
        $this->assertSame(0, Boutique::query()->count());
    }
}
