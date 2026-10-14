<?php

namespace Tests\Feature\Admin;

use App\Enums\Role;
use App\Enums\StatutBoutique;
use App\Enums\StatutCampagneBoutiques;
use App\Enums\StatutGrilleTarifaire;
use App\Enums\StatutSite;
use App\Models\CampagneBoutiques;
use App\Models\Site;
use App\Models\TypeBoutique;
use App\Models\User;
use App\Services\Boutiques\LotsCampagne;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\Support\ActeursParametrage;
use Tests\Support\GrillesTarifaires;
use Tests\TestCase;

/** Le GESTIONNAIRE DES SITES ET BOUTIQUES ne saisit, ne modifie et ne voit aucun montant ni paramètre financier. */
class GestionnaireSansFinancierTest extends TestCase
{
    use ActeursParametrage, GrillesTarifaires, RefreshDatabase;

    private Site $site;

    private TypeBoutique $boutique;

    private User $gestionnaire;

    protected function setUp(): void
    {
        parent::setUp();

        $this->creerActeurs();
        $this->gestionnaire = User::factory()->role(Role::GestionnaireSites)->create();
        $this->boutique = TypeBoutique::create(['code' => 'BOUT', 'libelle' => 'Boutique']);
        $this->site = Site::create(['statut' => StatutSite::Confirme, 'code' => 'RAA', 'nom' => 'Marché Raaga', 'region' => 'Centre', 'commune' => 'Ouagadougou',
            'adresse' => 'Avenue de la Nation', 'version' => 1, 'cree_par' => $this->regulateur->id]);
        foreach ([['RC', 9], ['R1', 12.5]] as $i => [$niveau, $surface]) {
            $this->site->boutiques()->create(['niveau' => $niveau, 'bloc' => 'A', 'numero' => sprintf('%03d', $i + 1), 'surface' => $surface,
                'type_boutique_id' => $this->boutique->id, 'statut' => StatutBoutique::Disponible]);
        }
    }

    public function test_le_loyer_lit_l_unite_de_la_ligne_de_grille(): void
    {
        // Même type, deux unités : forfait par local au rez-de-chaussée, au m² au R+1.
        $this->grilleConfirmee($this->site, ['RC' => [$this->boutique->id => [20000, 12, 60, 'unite']], 'R1' => [$this->boutique->id => [2000, 12, 36, 'm2']]]);
        $campagne = CampagneBoutiques::create([
            'statut' => StatutCampagneBoutiques::Confirmee, 'nom' => 'Attribution 2027', 'date_ouverture' => now()->subDay(), 'date_cloture' => now()->addMonth(),
            'delai_paiement_heures' => 72, 'canaux_actifs' => ['ORANGE_MONEY'], 'version' => 1, 'cree_par' => $this->regulateur->id,
        ]);
        app(LotsCampagne::class)->definir($campagne, [$this->site->id => [$this->boutique->id => true]]);
        app(LotsCampagne::class)->engager($campagne);

        $lots = $campagne->categories()->get()->keyBy('niveau');
        $this->assertSame(20000, (int) $lots['RC']->loyer_mensuel);
        $this->assertSame(25000, (int) $lots['R1']->loyer_mensuel);
    }

    public function test_le_gestionnaire_ne_voit_ni_ne_saisit_aucune_donnee_financiere(): void
    {
        $this->en($this->gestionnaire)->get(route('admin.sites.finances', $this->site))->assertForbidden();
        $this->en($this->gestionnaire)->put(route('admin.sites.finances.update', $this->site), ['cout_total' => 1000])->assertForbidden();
        $this->en($this->gestionnaire)->get(route('admin.boutiques.grilles'))->assertForbidden();
        $this->en($this->gestionnaire)->get(route('admin.boutiques.grilles.show', $this->site))->assertForbidden();
        $this->en($this->gestionnaire)->get(route('admin.sites.show', $this->site))->assertOk()
            ->assertDontSee('Données financières')->assertDontSee(route('admin.sites.finances', $this->site));
        $this->assertNull($this->site->fresh()->finances);
    }

    public function test_les_exports_du_gestionnaire_n_ont_aucune_colonne_financiere(): void
    {
        foreach ([route('admin.sites.excel.export'), route('admin.referencement.boutiques.export'), route('admin.referencement.boutiques.excel.export', ['site_id' => $this->site->id])] as $url) {
            $fichier = tempnam(sys_get_temp_dir(), 'export');
            file_put_contents($fichier, $this->en($this->gestionnaire)->get($url)->assertOk()->streamedContent());
            foreach (IOFactory::load($fichier)->getAllSheets() as $onglet) {
                $entetes = implode(' | ', array_filter($onglet->rangeToArray('A1:'.$onglet->getHighestColumn().'1')[0]));
                $this->assertDoesNotMatchRegularExpression('/co[uû]t|part attendue|compl[ée]ment|loyer|caution|avance|fcfa|montant/iu', $entetes, $url);
            }
        }
    }

    public function test_le_regulateur_saisit_et_le_validateur_confirme_les_donnees_financieres(): void
    {
        $route = fn (string $action = '') => route('admin.sites.finances'.$action, $this->site);

        $this->en($this->regulateur)->put($route('.update'), ['cout_total' => 1000, 'part_occupants' => 1500])->assertSessionHasErrors('part_occupants');
        $this->en($this->regulateur)->put($route('.update'), ['cout_total' => 1000, 'part_occupants' => 400, 'source_complement' => 'Budget communal'])->assertSessionHas('succes');
        $this->en($this->regulateur)->post($route('.soumettre'))->assertSessionHas('succes');
        $this->en($this->validateur)->get($route())->assertOk()->assertSee('Budget communal');
        $this->en($this->validateur)->post($route('.confirmer'))->assertSessionHas('succes');

        $finances = $this->site->fresh()->finances;
        $this->assertSame([StatutGrilleTarifaire::Confirme, 600], [$finances->statut, $finances->complement()]);
        $this->en($this->validateur)->get(route('admin.sites.show', $this->site))->assertOk()->assertSee('Données financières');
    }
}
