<?php

namespace Tests\Feature\Admin;

use App\Enums\PhaseSite;
use App\Enums\Role;
use App\Enums\StatutSite;
use App\Models\Site;
use App\Models\SiteFinance;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\Support\ActeursParametrage;
use Tests\TestCase;

/** Tableau de données réutilisable (<x-tableau>), appliqué à la liste des sites : traitement côté serveur. */
class TableauDonneesTest extends TestCase
{
    use ActeursParametrage, RefreshDatabase;

    private User $gestionnaire;

    protected function setUp(): void
    {
        parent::setUp();

        $this->creerActeurs();
        $this->gestionnaire = User::factory()->role(Role::GestionnaireSites)->create();
        // 30 sites : S01 à S30 ; un sur trois confirmé ; phases en alternance.
        foreach (range(1, 30) as $n) {
            $site = Site::create(['statut' => $n % 3 === 0 ? StatutSite::Confirme : StatutSite::Brouillon, 'code' => sprintf('S%02d', $n), 'nom' => sprintf('Marché %02d', $n),
                'ville' => $n <= 10 ? 'Bobo-Dioulasso' : 'Ouagadougou', 'phase' => $n % 2 === 0 ? PhaseSite::Travaux : PhaseSite::Etudes, 'cree_par' => $this->gestionnaire->id]);
            SiteFinance::create(['site_id' => $site->id, 'cout_total' => 1000000 * $n, 'statut' => 'BROUILLON']);
        }
    }

    private function liste(User $profil, array $parametres = []): TestResponse
    {
        return $this->en($profil)->get(route('admin.sites', $parametres))->assertOk();
    }

    /** Codes des sites affichés, dans l'ordre. @return list<string> */
    private function codes(TestResponse $reponse): array
    {
        preg_match_all('#<td class="font-mono font-semibold" data-libelle="Code">(S\d\d)</td>#', $reponse->getContent(), $m);

        return $m[1];
    }

    private function export(User $profil, array $parametres): array
    {
        $fichier = tempnam(sys_get_temp_dir(), 'export');
        file_put_contents($fichier, $this->en($profil)->get(route('admin.sites', [...$parametres, 'export' => 'xlsx']))->assertOk()->streamedContent());

        return IOFactory::load($fichier)->getActiveSheet()->toArray();
    }

    public function test_pagination_et_nombre_de_lignes(): void
    {
        $page = $this->liste($this->gestionnaire, ['lignes' => 10, 'page' => 2]);
        $this->assertSame(['S11', 'S12', 'S13', 'S14', 'S15', 'S16', 'S17', 'S18', 'S19', 'S20'], $this->codes($page));
        $page->assertSee('Affichage de 11 à 20 sur 30 lignes')->assertSee('aria-current="page" aria-label="Page 2"', false)
            ->assertSee('Précédent')->assertSee('Suivant')->assertSee('<option value="10" selected>', false);

        // Valeurs inconnues ignorées : 25 lignes par défaut, page hors limite ramenée à la dernière.
        $this->assertCount(25, $this->codes($this->liste($this->gestionnaire, ['lignes' => 7])));
        $this->assertSame(['S26', 'S27', 'S28', 'S29', 'S30'], $this->codes($this->liste($this->gestionnaire, ['page' => 99])));
    }

    public function test_tri_par_en_tete(): void
    {
        $page = $this->liste($this->gestionnaire, ['tri' => '-code', 'lignes' => 10]);
        $this->assertSame('S30', $this->codes($page)[0]);
        $page->assertSee('aria-sort="descending"', false);
        // Tri par défaut : nom croissant ; colonne inconnue ignorée.
        $this->assertSame('S01', $this->codes($this->liste($this->gestionnaire, ['tri' => 'inexistante']))[0]);
    }

    public function test_recherche_et_filtres_combines(): void
    {
        $this->liste($this->gestionnaire, ['q' => 'Bobo'])->assertSee('Affichage de 1 à 10 sur 10 lignes (filtrées sur 30)');
        $page = $this->liste($this->gestionnaire, ['q' => 'Ouaga', 'statut' => 'CONFIRME', 'phase' => 'TRAVAUX']);
        // Ouagadougou (11 à 30), confirmé (multiple de 3), travaux (pair) : 12, 18, 24, 30.
        $this->assertSame(['S12', 'S18', 'S24', 'S30'], $this->codes($page));
        $page->assertSee('(filtrées sur 30)');
        // Filtre hors de la liste proposée : ignoré.
        $this->assertCount(25, $this->codes($this->liste($this->gestionnaire, ['statut' => 'INCONNU'])));
    }

    public function test_etat_conserve_dans_l_adresse(): void
    {
        $page = $this->liste($this->gestionnaire, ['q' => 'Ouaga', 'statut' => 'BROUILLON', 'tri' => '-code', 'lignes' => 10]);
        // Pagination et tri conservent recherche, filtres, tri et nombre de lignes.
        $page->assertSee(e(route('admin.sites', ['q' => 'Ouaga', 'tri' => '-code', 'lignes' => 10, 'page' => 2, 'statut' => 'BROUILLON'])), false)
            ->assertSee(e(route('admin.sites', ['q' => 'Ouaga', 'tri' => 'code', 'lignes' => 10, 'statut' => 'BROUILLON'])), false)
            ->assertSee('value="Ouaga"', false)->assertSee('<option value="BROUILLON" selected>', false);

        // Rechargement du seul bloc des résultats (en-tête X-Tableau) : même état, sans la page autour.
        $partiel = $this->en($this->gestionnaire)->withHeader('X-Tableau', 'sites')->get(route('admin.sites', ['q' => 'Ouaga', 'statut' => 'BROUILLON', 'tri' => '-code', 'lignes' => 10]))->assertOk();
        $this->assertSame($this->codes($page), $this->codes($partiel));
        $partiel->assertDontSee('<html', false)->assertSee('data-tableau-resultats', false);
    }

    public function test_export_du_resultat_filtre(): void
    {
        $lignes = $this->export($this->regulateur, ['q' => 'Ouaga', 'statut' => 'CONFIRME', 'phase' => 'TRAVAUX', 'tri' => '-code']);
        $this->assertSame(['Code', 'Site', 'Ville · arrondissement', 'Phase', 'Statut', 'Boutiques', 'Version', 'Coût total (FCFA)'], $lignes[0]);
        $this->assertSame(['S30', 'S24', 'S18', 'S12'], array_column(array_slice($lignes, 1), 0));
        $this->assertSame('Confirmé', $lignes[1][4]);
    }

    public function test_colonne_financiere_absente_pour_le_gestionnaire(): void
    {
        $this->liste($this->regulateur)->assertSee('Coût total (FCFA)')->assertSee("1\u{00A0}000\u{00A0}000", false);
        $page = $this->liste($this->gestionnaire)->assertDontSee('Coût total')->assertDontSee("1\u{00A0}000\u{00A0}000", false);
        $page->assertDontSee('data-libelle="Coût total', false);

        $lignes = $this->export($this->gestionnaire, []);
        $this->assertNotContains('Coût total (FCFA)', $lignes[0]);
        $this->assertSame(['Code', 'Site', 'Ville · arrondissement', 'Phase', 'Statut', 'Boutiques', 'Version'], $lignes[0]);
        $this->assertCount(31, $lignes);
        // Tri sur une colonne interdite : ignoré.
        $this->assertSame('S01', $this->codes($this->liste($this->gestionnaire, ['tri' => '-cout_total']))[0]);
    }
}
