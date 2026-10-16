<?php

namespace Tests\Feature\Admin;

use App\Enums\Role;
use App\Enums\StatutTypeBoutique;
use App\Models\Niveau;
use App\Models\Unite;
use Database\Seeders\ReferentielsSitesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\ConnexionBackoffice;
use Tests\TestCase;

/**
 * Menu dépliant « Référentiels » de la barre latérale : types de boutiques, niveaux, zones. Seulement pour le
 * GESTIONNAIRE DES SITES ET BOUTIQUES (gestion) et le SUPER_ADMIN (lecture) ; les unités sont près de la grille tarifaire.
 */
class MenuReferentielsTest extends TestCase
{
    use ConnexionBackoffice, RefreshDatabase;

    private const SOUS_MENUS = ['types' => 'Types de boutiques', 'niveaux' => 'Niveaux', 'zones' => 'Zones'];

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(ReferentielsSitesSeeder::class);
    }

    /** Contenu du menu dépliant (bouton et sous-menus) de la page, ou chaîne vide. */
    private function menu(string $html): string
    {
        return preg_match('#<li class="adm-deroulant" x-data="menuDeroulant\(\{ deplie: \w+, cle: \'referentiels\'.*?</ul>\s*</div>\s*</li>#s', $html, $m) ? $m[0] : '';
    }

    public static function profils(): array
    {
        return ['SUPER_ADMIN' => [Role::SuperAdmin], 'GESTIONNAIRE_SITES' => [Role::GestionnaireSites]];
    }

    #[DataProvider('profils')]
    public function test_les_trois_sous_menus_selon_le_profil(Role $role): void
    {
        $this->connecterBackoffice($role);
        $menu = $this->menu($this->get(route('admin.tableau'))->assertOk()->getContent());

        $this->assertStringContainsString('aria-controls="sous-menu-referentiels"', $menu);
        $this->assertStringContainsString('Référentiels', $menu);
        $this->assertStringNotContainsString('<a href', explode('<div id="sous-menu-referentiels"', $menu)[0], 'l’entrée elle-même n’est pas un lien');
        foreach (['admin.sites.types', 'admin.referencement.niveaux', 'admin.referencement.zones'] as $route) {
            $this->assertStringContainsString('href="'.route($route).'"', $menu);
        }
        $this->assertStringNotContainsString(route('admin.referencement.unites'), $menu);
        foreach (self::SOUS_MENUS as $libelle) {
            $this->assertStringContainsString($libelle, $menu);
        }
    }

    public function test_referentiels_en_tete_du_groupe_pour_le_gestionnaire(): void
    {
        $position = fn (string $html, string $motif) => strpos($html, $motif);

        $this->connecterBackoffice(Role::GestionnaireSites);
        $html = $this->get(route('admin.tableau'))->assertOk()->getContent();
        $this->assertLessThan($position($html, 'href="'.route('admin.sites').'"'), $position($html, 'aria-controls="sous-menu-referentiels"'));

        // SUPER_ADMIN : à sa place, après les imports.
        $this->connecterBackoffice(Role::SuperAdmin);
        $html = $this->get(route('admin.tableau'))->assertOk()->getContent();
        $this->assertGreaterThan($position($html, 'href="'.route('admin.referencement.imports.entite', 'boutiques').'"'), $position($html, 'aria-controls="sous-menu-referentiels"'));
    }

    public function test_entree_absente_sans_aucun_droit(): void
    {
        $this->connecterBackoffice(Role::Admin);
        $page = $this->get(route('admin.tableau'))->assertOk()->getContent();

        $this->assertSame('', $this->menu($page));
        $this->assertStringNotContainsString('sous-menu-referentiels', $page);
        $this->get(route('admin.referencement.unites'))->assertForbidden();
        $this->get(route('admin.sites.types'))->assertForbidden();

        // VALIDATEUR et RÉGULATEUR : plus d'onglet « Référentiels ».
        foreach ([Role::Validateur, Role::Regulateur] as $role) {
            $this->connecterBackoffice($role);
            $this->assertStringNotContainsString('sous-menu-referentiels', $this->get(route('admin.tableau'))->assertOk()->getContent(), $role->value);
        }
    }

    public function test_menu_ouvert_sur_une_page_de_sous_menu_et_etat_memorise(): void
    {
        $this->connecterBackoffice(Role::GestionnaireSites);

        // Page hors du menu, aucun état mémorisé : replié.
        $this->assertStringContainsString('aria-expanded="false"', $this->menu($this->get(route('admin.sites'))->getContent()));

        // Page d'un sous-menu : déplié, sous-menu surligné.
        foreach (['admin.referencement.niveaux', 'admin.referencement.zones', 'admin.sites.types'] as $route) {
            $menu = $this->menu($this->get(route($route))->assertOk()->getContent());
            $this->assertStringContainsString('aria-expanded="true"', $menu, $route);
            $this->assertMatchesRegularExpression('#<a href="'.preg_quote(route($route), '#').'"[^>]*bo-lien-actif[^>]*aria-current="page"#', $menu);
        }
        // Fiche d'un niveau : le sous-menu « Niveaux » reste surligné.
        $menu = $this->menu($this->get(route('admin.referencement.zones.show', ['niveaux', Niveau::query()->value('id')]))->assertOk()->getContent());
        $this->assertMatchesRegularExpression('#<a href="'.preg_quote(route('admin.referencement.niveaux'), '#').'"[^>]*aria-current="page"#', $menu);

        // État ouvert mémorisé pour l'utilisateur.
        $this->postJson(route('admin.preferences.menu'), ['menu' => 'referentiels', 'ouvert' => true])->assertOk();
        $this->assertStringContainsString('aria-expanded="true"', $this->menu($this->get(route('admin.sites'))->getContent()));
        $this->postJson(route('admin.preferences.menu'), ['menu' => 'referentiels', 'ouvert' => false])->assertOk();
        $this->assertStringContainsString('aria-expanded="false"', $this->menu($this->get(route('admin.sites'))->getContent()));
    }

    public function test_unites_reservees_au_regulateur_et_lues_par_le_super_admin(): void
    {
        $unite = Unite::query()->where('code', 'M2')->firstOrFail();
        $this->assertSame(['M2', 'REDEVANCE', 'UNITE'], Unite::query()->orderBy('code')->pluck('code')->all());

        // GESTIONNAIRE et VALIDATEUR : plus d'accès aux unités (le VALIDATEUR décide depuis « À valider »).
        foreach ([Role::GestionnaireSites, Role::Validateur] as $role) {
            $this->connecterBackoffice($role);
            $this->get(route('admin.referencement.unites'))->assertForbidden();
            $this->get(route('admin.referencement.zones.show', ['unites', $unite->id]))->assertForbidden();
        }
        $this->connecterBackoffice(Role::SuperAdmin);
        $this->get(route('admin.referencement.unites'))->assertOk()->assertSee('Au mètre carré par mois')->assertDontSee('Ajouter une unité');
        $this->connecterBackoffice(Role::GestionnaireSites);
        $this->post(route('admin.referencement.zones.store', 'unites'), ['code' => 'FORFAIT', 'libelle' => 'Forfait'])->assertForbidden();
        $this->put(route('admin.referencement.zones.update', ['unites', $unite->id]), ['libelle' => 'Autre'])->assertForbidden();
        $this->post(route('admin.referencement.zones.desactiver', ['unites', $unite->id]))->assertForbidden();
        $this->delete(route('admin.referencement.zones.destroy', ['unites', $unite->id]))->assertForbidden();

        // Le RÉGULATEUR saisit (soumis au VALIDATEUR) ; le code ne change jamais.
        $this->connecterBackoffice(Role::Regulateur);
        $this->get(route('admin.referencement.unites'))->assertSee('Ajouter une unité');
        $this->post(route('admin.referencement.zones.store', 'unites'), ['code' => 'forfait', 'libelle' => 'Forfait mensuel'])->assertSessionHas('succes');
        $this->assertSame(StatutTypeBoutique::AValider, Unite::query()->where('code', 'FORFAIT')->value('statut'));
        $this->post(route('admin.referencement.zones.store', 'unites'), ['code' => 'M2', 'libelle' => 'Doublon'])->assertSessionHasErrors('code');
        $this->post(route('admin.referencement.zones.confirmer', ['unites', Unite::query()->where('code', 'FORFAIT')->value('id')]))->assertForbidden();
    }

    public function test_anciennes_adresses_redirigees(): void
    {
        $this->connecterBackoffice(Role::GestionnaireSites);
        $base = url('admin/referencement');

        $this->get($base.'/types')->assertStatus(301)->assertRedirect($base.'/referentiels/types');
        $this->get($base.'/types/3')->assertStatus(301)->assertRedirect($base.'/referentiels/types/3');
        $this->get($base.'/niveaux')->assertStatus(301)->assertRedirect($base.'/referentiels/niveaux');
        $this->get($base.'/zones/4?page=2')->assertStatus(301)->assertRedirect($base.'/referentiels/zones/4?page=2');
        $this->get($base.'/niveaux-zones')->assertStatus(301)->assertRedirect(url('admin/referencement/referentiels/niveaux'));
        $this->get(url('admin/sites/types'))->assertStatus(301)->assertRedirect($base.'/referentiels/types');
        $this->get($base.'/referentiels/niveaux')->assertOk();
    }
}
