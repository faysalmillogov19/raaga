<?php

namespace Tests\Feature\Admin;

use App\Enums\Role;
use App\Enums\StatutBoutique;
use App\Enums\StatutGrilleTarifaire;
use App\Enums\StatutSite;
use App\Models\GrilleTarifaire;
use App\Models\Site;
use App\Models\TypeBoutique;
use App\Models\Unite;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use PhpOffice\PhpSpreadsheet\Calculation\Calculation;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\Support\ActeursParametrage;
use Tests\TestCase;

/**
 * Grille tarifaire en Excel : fichier à compléter (une ligne par niveau et type, valeurs proposées, montants de chaque
 * boutique calculés par formule), import du fichier rempli en brouillon, refus complet d'un fichier en erreur, droits.
 */
class GrilleTarifaireExcelTest extends TestCase
{
    use ActeursParametrage, RefreshDatabase;

    private Site $site;

    private TypeBoutique $boutique;

    private TypeBoutique $magasin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->creerActeurs();
        $this->boutique = TypeBoutique::create(['code' => 'BOUT', 'libelle' => 'Boutique']);
        $this->magasin = TypeBoutique::create(['code' => 'MAG', 'libelle' => 'Magasin']);
        $this->site = Site::create(['statut' => StatutSite::Confirme, 'code' => 'RAA', 'nom' => 'Marché Raaga', 'region' => 'Centre', 'commune' => 'Ouagadougou',
            'adresse' => 'Avenue de la Nation', 'version' => 1, 'cree_par' => $this->regulateur->id]);
        foreach ([['RC', 'A', 1, 9, $this->boutique], ['RC', 'A', 2, 9, $this->boutique], ['R1', 'B', 1, 12.5, $this->boutique], ['RC', 'M', 1, 40, $this->magasin]] as [$niveau, $bloc, $n, $surface, $type]) {
            $this->site->boutiques()->create(['niveau' => $niveau, 'bloc' => $bloc, 'numero' => sprintf('%03d', $n), 'surface' => $surface,
                'type_boutique_id' => $type->id, 'statut' => StatutBoutique::Disponible]);
        }
    }

    private function telecharger(?User $user = null): Spreadsheet
    {
        $reponse = $this->en($user ?? $this->regulateur)->get(route('admin.boutiques.grilles.excel', $this->site))->assertOk();
        $this->assertStringContainsString('grille-tarifaire-raa-', strtolower((string) $reponse->headers->get('Content-Disposition')));
        $chemin = tempnam(sys_get_temp_dir(), 'grille').'.xlsx';
        file_put_contents($chemin, $reponse->streamedContent());

        return IOFactory::load($chemin);
    }

    /** Remplit l'onglet « Grille » (ligne => [unité, loyer, caution, avance]) et rend le fichier à importer. */
    private function remplir(Spreadsheet $classeur, array $valeurs): UploadedFile
    {
        $grille = $classeur->getSheetByName('Grille');
        foreach ($valeurs as $ligne => [$unite, $loyer, $caution, $avance]) {
            $grille->setCellValue("G{$ligne}", $unite)->setCellValue("H{$ligne}", $loyer)->setCellValue("I{$ligne}", $caution)->setCellValue("J{$ligne}", $avance);
        }
        $chemin = tempnam(sys_get_temp_dir(), 'grille').'.xlsx';
        (new Xlsx($classeur))->save($chemin);

        return new UploadedFile($chemin, 'grille-remplie.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
    }

    /** Lignes du fichier, par « niveau|type ». */
    private function lignes(Spreadsheet $classeur): array
    {
        $grille = $classeur->getSheetByName('Grille');
        $lignes = [];
        for ($n = 2; $n <= $grille->getHighestDataRow(); $n++) {
            $lignes[$grille->getCell("A{$n}")->getValue().'|'.$grille->getCell("C{$n}")->getValue()] = $n;
        }

        return $lignes;
    }

    public function test_le_fichier_a_completer_et_ses_formules(): void
    {
        $classeur = $this->telecharger();
        $this->assertSame(['Grille', 'Boutiques', 'Mode d’emploi'], $classeur->getSheetNames());
        $grille = $classeur->getSheetByName('Grille');
        $this->assertSame(['Niveau', 'Libellé du niveau', 'Type', 'Libellé du type', 'Boutiques', 'Surfaces (nombre)', 'Unité du loyer',
            'Loyer mensuel (FCFA, selon l’unité)', 'Caution (mois)', 'Avance (mois)'], array_slice($grille->rangeToArray('A1:K1')[0], 0, 10));

        // Une ligne par niveau et type présents ; nombre de boutiques, surfaces ; mois proposés (caution 12, avance selon le niveau ou le type).
        $lignes = $this->lignes($classeur);
        $this->assertEqualsCanonicalizing(['RC|BOUT', 'RC|MAG', 'R1|BOUT'], array_keys($lignes));
        $rcBout = $lignes['RC|BOUT'];
        $this->assertSame([2, 'M2', null, 12, 60], [$grille->getCell("E{$rcBout}")->getValue(), $grille->getCell("G{$rcBout}")->getValue(),
            $grille->getCell("H{$rcBout}")->getValue(), $grille->getCell("I{$rcBout}")->getValue(), $grille->getCell("J{$rcBout}")->getValue()]);
        $this->assertSame(12, $grille->getCell('J'.$lignes['RC|MAG'])->getValue());
        $this->assertSame(36, $grille->getCell('J'.$lignes['R1|BOUT'])->getValue());
        $this->assertStringContainsString('9', (string) $grille->getCell("F{$rcBout}")->getValue());
        // Colonnes d'identification verrouillées, saisies déverrouillées.
        $this->assertTrue($grille->getProtection()->getSheet());
        $this->assertSame('unprotected', $grille->getStyle("H{$rcBout}")->getProtection()->getLocked());
        $this->assertNotSame('unprotected', $grille->getStyle("A{$rcBout}")->getProtection()->getLocked());

        // Onglet de contrôle : chaque boutique, montants calculés à partir de la grille (9 m² × 2 500 = 22 500 ; caution × 12 ; avance × 60).
        $grille->setCellValue("H{$rcBout}", 2500);
        // Élagage des branches désactivé : défaut connu de PhpSpreadsheet sur les IF imbriqués (Excel et LibreOffice calculent sans).
        Calculation::getInstance($classeur)->setBranchPruningEnabled(false);
        Calculation::getInstance($classeur)->clearCalculationCache();
        $boutiques = $classeur->getSheetByName('Boutiques');
        $this->assertSame(5, $boutiques->getHighestDataRow());
        $ligne = collect(range(2, 5))->first(fn ($n) => $boutiques->getCell("A{$n}")->getValue() === 'RAA-RC-A-001');
        $this->assertEquals([22500, 270000, 1350000], [$boutiques->getCell("G{$ligne}")->getCalculatedValue(),
            $boutiques->getCell("H{$ligne}")->getCalculatedValue(), $boutiques->getCell("I{$ligne}")->getCalculatedValue()]);
        // Sans loyer : pas de montant.
        $magasin = collect(range(2, 5))->first(fn ($n) => $boutiques->getCell("E{$n}")->getValue() === 'MAG');
        $this->assertSame('', $boutiques->getCell("G{$magasin}")->getCalculatedValue());

        // Le formulaire propose le téléchargement et l'import ; la fiche, le téléchargement.
        $this->en($this->regulateur)->get(route('admin.boutiques.grilles.edit', $this->site))->assertOk()
            ->assertSee('data-grille-excel', false)->assertSee(route('admin.boutiques.grilles.excel', $this->site), false)
            ->assertSee(route('admin.boutiques.grilles.importer', $this->site), false);
        $this->en($this->regulateur)->get(route('admin.boutiques.grilles.show', $this->site))->assertSee('Télécharger le fichier Excel');
    }

    public function test_import_du_fichier_rempli_en_brouillon_puis_soumission(): void
    {
        $classeur = $this->telecharger();
        $l = $this->lignes($classeur);
        // RC|BOUT au m², RC|MAG à l'unité, R1|BOUT laissé sans loyer (pas de ligne).
        $fichier = $this->remplir($classeur, [$l['RC|BOUT'] => ['M2', 2500, 12, 60], $l['RC|MAG'] => ['UNITE', 150000, 6, 12]]);

        $this->en($this->regulateur)->post(route('admin.boutiques.grilles.importer', $this->site), ['fichier' => $fichier])
            ->assertRedirect(route('admin.boutiques.grilles.edit', $this->site))
            ->assertSessionHas('succes', 'Grille importée : 2 ligne(s). Relisez-la, puis soumettez-la au VALIDATEUR.');
        $grille = GrilleTarifaire::query()->where('site_id', $this->site->id)->sole();
        $this->assertSame(StatutGrilleTarifaire::Brouillon, $grille->statut);
        $lignes = $grille->lignes()->with('unite')->get()->keyBy(fn ($x) => $x->niveau.'|'.$x->type_boutique_id);
        $this->assertCount(2, $lignes);
        $this->assertSame(['M2', 2500, 12, 60], [$lignes['RC|'.$this->boutique->id]->unite->code, $lignes['RC|'.$this->boutique->id]->loyer_m2,
            $lignes['RC|'.$this->boutique->id]->mois_caution, $lignes['RC|'.$this->boutique->id]->mois_avance]);
        $this->assertSame(['UNITE', 150000, 6, 12], [$lignes['RC|'.$this->magasin->id]->unite->code, $lignes['RC|'.$this->magasin->id]->loyer_m2,
            $lignes['RC|'.$this->magasin->id]->mois_caution, $lignes['RC|'.$this->magasin->id]->mois_avance]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'grille_tarifaire.importee', 'acteur_id' => $this->regulateur->id]);

        // Le formulaire montre les valeurs importées ; le fichier suivant les reprend.
        $this->en($this->regulateur)->get(route('admin.boutiques.grilles.edit', $this->site))->assertSee('value="150000"', false);
        $repris = $this->telecharger();
        $this->assertSame(150000, $repris->getSheetByName('Grille')->getCell('H'.$this->lignes($repris)['RC|MAG'])->getValue());

        // Soumission au VALIDATEUR, comme une saisie à l'écran ; ensuite, plus d'import.
        $this->en($this->regulateur)->post(route('admin.boutiques.grilles.soumettre', $this->site))->assertSessionHas('succes');
        $this->en($this->regulateur)->post(route('admin.boutiques.grilles.importer', $this->site), ['fichier' => $this->remplir($repris, [])])
            ->assertSessionHas('erreur', fn ($m) => str_contains($m, 'pas modifiable'));
    }

    public function test_un_fichier_en_erreur_n_enregistre_rien(): void
    {
        $classeur = $this->telecharger();
        $l = $this->lignes($classeur);
        $grille = $classeur->getSheetByName('Grille');
        // Combinaison inexistante et doublon ajoutés en bas, valeurs invalides.
        $grille->fromArray(['R3', 'R+3', 'BOUT', 'Boutique'], null, 'A5');
        $grille->fromArray(['RC', 'Rez-de-chaussée', 'MAG', 'Magasin'], null, 'A6');
        $fichier = $this->remplir($classeur, [$l['RC|BOUT'] => ['M3', -5, 12, 60], $l['RC|MAG'] => ['UNITE', 1000, 300, 12], 5 => ['M2', 1000, 12, 12], 6 => ['M2', 1000, 12, 12]]);

        $this->en($this->regulateur)->post(route('admin.boutiques.grilles.importer', $this->site), ['fichier' => $fichier])
            ->assertRedirect(route('admin.boutiques.grilles.edit', $this->site))->assertSessionHas('erreur', 'Import refusé : 5 erreurs à corriger dans le fichier.');
        $this->assertSame(0, GrilleTarifaire::query()->count());
        $page = $this->en($this->regulateur)->followingRedirects()->post(route('admin.boutiques.grilles.importer', $this->site), ['fichier' => $fichier])->assertOk();
        $page->assertSee('data-erreurs-import', false)->assertSee('Loyer invalide')->assertSee('Unité invalide')->assertSee('Nombre de mois invalide')
            ->assertSee('cette combinaison n’existe pas sur ce site', false)->assertSee('Ligne en double');

        // Aucun loyer : refusé ; fichier qui n'est pas la grille : refusé.
        $this->en($this->regulateur)->post(route('admin.boutiques.grilles.importer', $this->site), ['fichier' => $this->remplir($this->telecharger(), [])])
            ->assertSessionHas('erreur');
        $autre = new Spreadsheet;
        $autre->getActiveSheet()->setTitle('Locaux');
        $chemin = tempnam(sys_get_temp_dir(), 'autre').'.xlsx';
        (new Xlsx($autre))->save($chemin);
        $this->en($this->regulateur)->followingRedirects()->post(route('admin.boutiques.grilles.importer', $this->site), ['fichier' => new UploadedFile($chemin, 'autre.xlsx', null, null, true)])
            ->assertSee('Onglet « Grille » introuvable', false);
        $this->en($this->regulateur)->post(route('admin.boutiques.grilles.importer', $this->site), ['fichier' => UploadedFile::fake()->create('grille.csv', 10, 'text/csv')])
            ->assertSessionHasErrors('fichier');
        $this->assertSame(0, GrilleTarifaire::query()->count());
    }

    public function test_droits(): void
    {
        $fichier = $this->remplir($this->telecharger(), [2 => ['M2', 2500, 12, 60]]);
        // Réservé au RÉGULATEUR (le SUPER_ADMIN télécharge, en lecture seule) ; le VALIDATEUR décide depuis « À valider »,
        // le GESTIONNAIRE n'a pas la grille.
        // Plus de grille tarifaire pour le SUPER_ADMIN depuis le 30/10/2026.
        $this->en($this->superAdmin)->get(route('admin.boutiques.grilles.excel', $this->site))->assertForbidden();
        $this->en($this->superAdmin)->post(route('admin.boutiques.grilles.importer', $this->site), ['fichier' => $fichier])->assertForbidden();
        $this->en($this->validateur)->get(route('admin.boutiques.grilles.excel', $this->site))->assertForbidden();
        $this->en($this->validateur)->post(route('admin.boutiques.grilles.importer', $this->site), ['fichier' => $fichier])->assertForbidden();
        $gestionnaire = User::factory()->role(Role::GestionnaireSites)->create();
        $this->en($gestionnaire)->get(route('admin.boutiques.grilles.excel', $this->site))->assertForbidden();
        $this->en($gestionnaire)->post(route('admin.boutiques.grilles.importer', $this->site), ['fichier' => $fichier])->assertForbidden();
        $this->assertSame(0, GrilleTarifaire::query()->count());
        $this->assertNotNull(Unite::idDuCode('M2'));
    }
}
