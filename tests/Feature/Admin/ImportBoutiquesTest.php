<?php

namespace Tests\Feature\Admin;

use App\Enums\Role;
use App\Enums\StatutBoutique;
use App\Enums\StatutSite;
use App\Models\AuditLog;
use App\Models\Boutique;
use App\Models\Site;
use App\Models\TypeBoutique;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\Support\ActeursParametrage;
use Tests\TestCase;

class ImportBoutiquesTest extends TestCase
{
    use ActeursParametrage, RefreshDatabase;

    private User $gestionnaire;

    private Site $site;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        $this->creerActeurs();
        $this->gestionnaire = User::factory()->role(Role::GestionnaireSites)->create();
        TypeBoutique::create(['code' => 'BOUT', 'libelle' => 'Boutique']);
        TypeBoutique::create(['code' => 'ETAL', 'libelle' => 'Étal']);
        $this->site = Site::create([
            'statut' => StatutSite::Brouillon, 'code' => 'RAA', 'nom' => 'Marché Raaga', 'region' => 'Centre',
            'commune' => 'Ouagadougou', 'adresse' => 'Avenue de la Nation', 'cree_par' => $this->gestionnaire->id,
        ]);
    }

    /** Lignes [site, niveau, bloc, numéro, type, surface, planche, observations] écrites dans le classeur, à partir de la ligne 2. */
    private function fichier(array $lignes, ?Spreadsheet $classeur = null): UploadedFile
    {
        $classeur ??= tap(new Spreadsheet, fn ($c) => $c->getActiveSheet()->setTitle('Locaux')->fromArray(
            ['Code (calculé)', 'Site', 'Niveau', 'Bloc ou zone', 'Numéro', 'Type', 'Surface (m²)', 'Planche', 'Observations', 'Contrôle'],
        ));
        foreach ($lignes as $i => $ligne) {
            $classeur->getSheetByName('Locaux')->fromArray($ligne, null, 'B'.($i + 2));
        }
        $chemin = tempnam(sys_get_temp_dir(), 'locaux').'.xlsx';
        (new Xlsx($classeur))->save($chemin);

        return new UploadedFile($chemin, 'locaux.xlsx', null, null, true);
    }

    private function deposer(UploadedFile $fichier): TestResponse
    {
        return $this->en($this->gestionnaire)->post(route('admin.sites.import.deposer', $this->site), ['fichier' => $fichier]);
    }

    public function test_import_valide_a_partir_du_modele(): void
    {
        // Le modèle téléchargé sert de base : onglet « Locaux », colonnes en ligne 1.
        $reponse = $this->en($this->gestionnaire)->get(route('admin.sites.modele', $this->site))->assertOk();
        $modele = tempnam(sys_get_temp_dir(), 'modele');
        file_put_contents($modele, $reponse->streamedContent());
        $classeur = IOFactory::load($modele);
        $this->assertSame('Code (calculé)', $classeur->getSheetByName('Locaux')->getCell('A1')->getValue());
        $this->assertSame('Contrôle', $classeur->getSheetByName('Locaux')->getCell('J1')->getValue());

        $fichier = $this->fichier([
            ['RAA', 'R1', 'A', 1, 'BOUT', 9, 'P2', 'Vitrine'],
            ['raa', 'r1', 'a', '2', 'Boutique', '9,5', null, null],
            [null, null, null, null, null, null, null, null],
            ['RAA', 'RC', 'K', 3, 'ETAL', 4, null, null],
        ], $classeur);
        $this->deposer($fichier)->assertRedirect(route('admin.sites.import', $this->site));

        // Aperçu : rien n'est encore enregistré.
        $this->en($this->gestionnaire)->get(route('admin.sites.import', $this->site))->assertOk()
            ->assertSee('3 lignes valides')->assertSee('R+1')->assertSee('Rez-de-chaussée')->assertDontSee('erreur');
        $this->assertSame(0, Boutique::query()->count());

        $this->en($this->gestionnaire)->post(route('admin.sites.import.confirmer', $this->site))->assertSessionHas('succes');
        $this->assertSame(['RAA-R1-A-001', 'RAA-R1-A-002', 'RAA-RC-K-003'], Boutique::query()->orderBy('code')->pluck('code')->all());
        $this->assertSame('9.50', Boutique::query()->where('code', 'RAA-R1-A-002')->value('surface'));
        $this->assertSame('Vitrine', Boutique::query()->where('code', 'RAA-R1-A-001')->value('observations'));

        // Journal : auteur, fichier, empreinte, nombre de boutiques ; fichier conservé hors du dossier public.
        $journal = AuditLog::query()->where('action', 'boutiques.importees')->sole();
        $this->assertSame($this->gestionnaire->id, $journal->acteur_id);
        $this->assertSame(['fichier' => 'locaux.xlsx', 'empreinte' => hash_file('sha256', $fichier->getPathname()), 'creees' => 3, 'mises_a_jour' => 0], $journal->nouvelle_valeur);
        $this->assertCount(1, Storage::disk('local')->allFiles('imports/boutiques/'.$this->site->id));

        // Export dans le même format.
        $export = tempnam(sys_get_temp_dir(), 'export');
        file_put_contents($export, $this->en($this->gestionnaire)->get(route('admin.sites.export', $this->site))->assertOk()->streamedContent());
        $feuille = IOFactory::load($export)->getSheetByName('Locaux');
        $this->assertSame(['RAA', 'R1', 'A', 1, 'BOUT'], [$feuille->getCell('B2')->getValue(), $feuille->getCell('C2')->getValue(),
            $feuille->getCell('D2')->getValue(), $feuille->getCell('E2')->getValue(), $feuille->getCell('F2')->getValue()]);
    }

    public function test_un_fichier_avec_erreurs_est_refuse_sans_rien_ecrire(): void
    {
        $this->deposer($this->fichier([
            ['SAR', 'R9', 'A', '0', 'XX', -1, null, null],
            ['RAA', 'R1', 'A', 1, 'BOUT', 9, null, null],
        ]));

        $this->en($this->gestionnaire)->get(route('admin.sites.import', $this->site))->assertOk()
            ->assertSee('Ligne 2 : site SAR inconnu. Vouliez-vous dire RAA ?')->assertSee('Valeur non autorisée')
            ->assertSee('Nombre entier de 1 à 999')->assertSee('Type « XX » inconnu', false)->assertSee('Surface supérieure à 0')
            ->assertSee('Confirmer l’import', false);

        $this->en($this->gestionnaire)->post(route('admin.sites.import.confirmer', $this->site))->assertSessionHas('erreur');
        $this->assertSame(0, Boutique::withTrashed()->count());
        $this->assertFalse(AuditLog::query()->where('action', 'boutiques.importees')->exists());
    }

    public function test_un_doublon_bloque_l_import(): void
    {
        $this->deposer($this->fichier([
            ['RAA', 'R1', 'A', 7, 'BOUT', 9, null, null],
            ['RAA', 'R1', 'A', '007', 'BOUT', 12, null, null],
        ]));

        $this->en($this->gestionnaire)->get(route('admin.sites.import', $this->site))->assertOk()
            ->assertSee('Doublon : le code RAA-R1-A-007 figure déjà ligne 2.');
        $this->en($this->gestionnaire)->post(route('admin.sites.import.confirmer', $this->site))->assertSessionHas('erreur');
        $this->assertSame(0, Boutique::query()->count());
    }

    public function test_une_boutique_engagee_n_est_pas_modifiee(): void
    {
        $type = TypeBoutique::query()->where('code', 'BOUT')->value('id');
        $libre = $this->site->boutiques()->create(['niveau' => 'R1', 'bloc' => 'A', 'numero' => '001', 'surface' => 9, 'type_boutique_id' => $type, 'statut' => StatutBoutique::Disponible]);
        $engagee = $this->site->boutiques()->create(['niveau' => 'R1', 'bloc' => 'A', 'numero' => '002', 'surface' => 9, 'type_boutique_id' => $type, 'statut' => StatutBoutique::EnCampagne]);

        $this->deposer($this->fichier([
            ['RAA', 'R1', 'A', 1, 'BOUT', 15, null, null],
            ['RAA', 'R1', 'A', 2, 'BOUT', 15, null, null],
        ]));
        $this->en($this->gestionnaire)->get(route('admin.sites.import', $this->site))->assertOk()
            ->assertSee('La boutique RAA-R1-A-002 est dans une campagne : elle ne peut pas être modifiée.');
        $this->en($this->gestionnaire)->post(route('admin.sites.import.confirmer', $this->site))->assertSessionHas('erreur');
        $this->assertSame('9.00', $engagee->fresh()->surface);
        $this->assertSame('9.00', $libre->fresh()->surface);

        // Sans la boutique engagée, la boutique libre est mise à jour (même code).
        $this->deposer($this->fichier([['RAA', 'R1', 'A', 1, 'BOUT', 15, null, null]]));
        $this->en($this->gestionnaire)->post(route('admin.sites.import.confirmer', $this->site))->assertSessionHas('succes');
        $this->assertSame('15.00', $libre->fresh()->surface);
        $this->assertSame(2, Boutique::query()->count());
    }

    public function test_les_autres_profils_n_ont_pas_acces(): void
    {
        foreach ([$this->validateur, $this->regulateur, User::factory()->role(Role::SuperAdmin)->create(), User::factory()->role(Role::Admin)->create()] as $profil) {
            $this->en($profil)->get(route('admin.sites.modele', $this->site))->assertForbidden();
            $this->en($profil)->get(route('admin.sites.export', $this->site))->assertForbidden();
            $this->en($profil)->post(route('admin.sites.import.deposer', $this->site), ['fichier' => $this->fichier([['RAA', 'R1', 'A', 1, 'BOUT', 9, null, null]])])->assertForbidden();
            $this->en($profil)->get(route('admin.sites.import', $this->site))->assertForbidden();
            $this->en($profil)->post(route('admin.sites.import.confirmer', $this->site))->assertForbidden();
        }
        $this->assertSame(0, Boutique::query()->count());
        $this->assertSame([], Storage::disk('local')->allFiles());
    }
}
