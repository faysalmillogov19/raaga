<?php

namespace Tests\Feature\Admin;

use App\Enums\Role;
use App\Models\DecisionPhase;
use App\Models\Site;
use App\Models\TypeBoutique;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\ActeursParametrage;
use Tests\TestCase;

/** Sous-menus de « À valider » : une page par rubrique, compteurs, groupage, menu ouvert, accès. */
class AValiderRubriquesTest extends TestCase
{
    use ActeursParametrage, RefreshDatabase;

    private User $gestionnaire;

    protected function setUp(): void
    {
        parent::setUp();

        $this->creerActeurs();
        $this->gestionnaire = User::factory()->role(Role::GestionnaireSites)->create();
        $this->en($this->gestionnaire)->post(route('admin.sites.types.store'), ['code' => 'BOUT', 'libelle' => 'Petite boutique', 'attribuable' => 1]);
        $this->en($this->validateur)->post(route('admin.sites.types.confirmer', TypeBoutique::query()->sole()))->assertSessionHas('succes');
        foreach (['SAR', 'A04'] as $code) {
            $this->en($this->gestionnaire)->post(route('admin.sites.store'), ['code' => $code, 'nom' => 'Marché '.$code, 'region' => 'Centre', 'ville' => 'Ouagadougou']);
            $site = Site::query()->where('code', $code)->sole();
            $this->en($this->gestionnaire)->post(route('admin.referencement.boutiques.store', $site), [
                'niveau' => 'R1', 'bloc' => 'A', 'numero' => 1, 'surface' => 9, 'type_boutique_id' => TypeBoutique::query()->value('id'), 'etat_physique' => 'LIVREE',
            ]);
            $this->en($this->gestionnaire)->post(route('admin.sites.soumettre', $site))->assertSessionHas('succes');
        }
        $this->en($this->regulateur)->post(route('admin.referencement.zones.store', 'unites'), ['code' => 'FORFAIT', 'libelle' => 'Forfait mensuel'])->assertSessionHas('succes');
    }

    /** Menu dépliant « À valider » de la page. */
    private function menu(string $html): string
    {
        return preg_match('#<li class="adm-deroulant" x-data="menuDeroulant\(\{ deplie: \w+, cle: \'a_valider\'.*?</ul>\s*</div>\s*</li>#s', $html, $m) ? $m[0] : '';
    }

    public function test_sous_menus_et_compteurs_pour_le_validateur(): void
    {
        $menu = $this->menu($this->en($this->validateur)->get(route('admin.tableau'))->assertOk()->getContent());

        // Entrée « À valider » : bouton, badge total (3) visible même menu fermé.
        $this->assertMatchesRegularExpression('#aria-controls="sous-menu-a_valider".*?<span class="adm-libelle">À valider</span>\s*<span class="adm-compteur">3<#s', $menu);
        $attendus = ['Vue d’ensemble' => [route('admin.a-valider'), '3'], 'Sites' => [route('admin.a-valider.rubrique', 'sites'), '2'],
            'Données financières des sites' => [route('admin.a-valider.rubrique', 'finances-sites'), '0'], 'Grilles tarifaires' => [route('admin.a-valider.rubrique', 'grilles'), '0'],
            'Référentiels' => [route('admin.a-valider.rubrique', 'referentiels'), '1'], 'Campagnes' => [route('admin.a-valider.rubrique', 'campagnes'), '0'],
            'Démarches du site public' => [route('admin.a-valider.rubrique', 'demarches'), '0'], 'Autorisations de modification' => [route('admin.a-valider.rubrique', 'autorisations-sites'), '0']];
        foreach ($attendus as $libelle => [$url, $n]) {
            $classe = $n === '0' ? 'adm-compteur adm-compteur-zero' : 'adm-compteur';
            $this->assertMatchesRegularExpression('#<a href="'.preg_quote($url, '#').'"[^>]*>\s*<span class="adm-libelle">'.preg_quote($libelle, '#').'</span>\s*<span class="'.$classe.'">'.$n.'<#', $menu, $libelle);
        }
    }

    public function test_page_d_une_rubrique_filtree(): void
    {
        $sites = $this->en($this->validateur)->get(route('admin.a-valider.rubrique', 'sites'))->assertOk();
        $sites->assertSee('<title>Sites', false)->assertSee('SAR · Marché SAR')->assertSee('A04 · Marché A04')->assertDontSee('FORFAIT · Forfait mensuel')
            ->assertSee('name="rubrique" value="sites"', false)->assertSee('href="'.route('admin.a-valider.rubrique', ['rubrique' => 'sites', 'detail' => 'site:'.Site::query()->where('code', 'SAR')->value('id')]).'"', false);

        $referentiels = $this->en($this->validateur)->get(route('admin.a-valider.rubrique', 'referentiels'))->assertOk();
        $referentiels->assertSee('<th scope="col">Liste</th>', false)->assertSee('FORFAIT · Forfait mensuel')->assertDontSee('SAR · Marché SAR');

        // Rubrique vide : état vide et lien vers la rubrique suivante qui a des éléments.
        $this->en($this->validateur)->get(route('admin.a-valider.rubrique', 'grilles'))->assertOk()->assertSee('Rien à valider dans cette rubrique.')
            ->assertSee('href="'.route('admin.a-valider.rubrique', 'referentiels').'"', false)->assertSee('Rubrique suivante : Référentiels (1)');
        $this->en($this->validateur)->get(url('admin/a-valider/inconnue'))->assertNotFound();
    }

    public function test_groupage_absent_sur_les_rubriques_sensibles(): void
    {
        $this->en($this->superAdmin)->post(route('admin.phase.proposer'), ['presentation_actions' => 1, 'souscription_actions' => 0, 'motif' => 'Ouverture prochaine']);
        $phase = DecisionPhase::query()->sole();

        $demarches = $this->en($this->validateur)->get(route('admin.a-valider.rubrique', 'demarches'))->assertOk();
        $demarches->assertSee('data-non-groupable', false)->assertDontSee('name="elements[]" value="phase:'.$phase->id.'"', false)
            ->assertDontSee('aria-label="Tout cocher', false)->assertSee('aria-label="Valider Présentation des actions visible', false);
        $this->en($this->validateur)->get(route('admin.a-valider.rubrique', 'sites'))->assertSee('aria-label="Tout cocher : Sites"', false);
    }

    public function test_menu_ouvert_sur_une_page_de_rubrique(): void
    {
        $menu = $this->menu($this->en($this->validateur)->get(route('admin.a-valider.rubrique', 'sites'))->getContent());

        $this->assertStringContainsString('aria-expanded="true"', $menu);
        $this->assertMatchesRegularExpression('#<a href="'.preg_quote(route('admin.a-valider.rubrique', 'sites'), '#').'"[^>]*bo-lien-actif[^>]*aria-current="page"#', $menu);
        $this->assertDoesNotMatchRegularExpression('#<a href="'.preg_quote(route('admin.a-valider'), '#').'"[^>]*aria-current#', $menu);

        // Vue d'ensemble : son propre sous-menu surligné.
        $menu = $this->menu($this->en($this->validateur)->get(route('admin.a-valider'))->getContent());
        $this->assertMatchesRegularExpression('#<a href="'.preg_quote(route('admin.a-valider'), '#').'"[^>]*aria-current="page"#', $menu);
    }

    public function test_acces_refuse_aux_autres_profils(): void
    {
        foreach ([$this->regulateur, $this->gestionnaire, $this->superAdmin, User::factory()->role(Role::Admin)->create()] as $profil) {
            $this->en($profil)->get(route('admin.a-valider.rubrique', 'sites'))->assertForbidden();
            $this->en($profil)->get(route('admin.a-valider.rubrique', 'demarches'))->assertForbidden();
            $this->assertSame('', $this->menu($this->en($profil)->get(route('admin.tableau'))->getContent()));
        }
    }
}
