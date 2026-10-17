<?php

namespace Tests\Feature\Admin;

use App\Enums\Role;
use App\Enums\StatutTypeBoutique;
use App\Models\Unite;
use App\Models\User;
use App\Support\MenuBackoffice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\ConnexionBackoffice;
use Tests\TestCase;

/**
 * Menu du RÉGULATEUR regroupé par domaine (menus dépliants) : chaque ancien onglet reste accessible avec la même URL,
 * aucun lien mort, badges remontés sur le menu parent, menu de la page en cours ouvert ; les autres profils gardent
 * la définition commune.
 */
class MenuRegulateurTest extends TestCase
{
    use ConnexionBackoffice, RefreshDatabase;

    private const MENUS = [
        // « Mes soumissions » en premier, juste après le tableau de bord (17/10/2026).
        'soumissions' => ['mes_brouillons', 'rejetes', 'en_attente'],
        'tarification' => ['grille', 'unites', 'finances', 'sites', 'capacites'],
        'location' => ['boutiques', 'cautions', 'boutiques_referentiel'],
        // Pages en préparation masquées : Référentiels, Simulations, Souscriptions, Alertes, Registre B, Espace régulateur,
        // Journal d'audit, Observations.
        'modalites' => ['moyens', 'delais', 'remboursements'],
        'invest' => ['investissement', 'tranches', 'interets', 'dividendes', 'kyc', 'dossiers_kyb', 'conventions', 'appels_fonds', 'registre_a'],
        'site_public' => ['phase'],
    ];

    /** Ancien menu : la définition commune filtrée par les droits du profil, sans les pages en préparation. */
    private function ancienMenu(User $user): array
    {
        return collect(MenuBackoffice::definition())->flatten(1)
            ->filter(fn (array $e) => $user->can($e['droit']->value) && ! (isset($e['masque_si']) && $user->can($e['masque_si']->value)))
            ->reject(fn (array $e) => MenuBackoffice::enPreparation($e['cle']))
            ->values()->all();
    }

    public function test_menus_deroulants_par_domaine_et_chaque_ancien_onglet_accessible(): void
    {
        $user = $this->connecterBackoffice(Role::Regulateur);
        $menu = MenuBackoffice::pour($user);
        $this->assertSame(['pilotage'], array_keys($menu));

        // Regroupement : Tableau de bord seul, puis les menus dépliants dans l'ordre demandé.
        $elements = MenuBackoffice::regrouper($menu['pilotage'], $user);
        $this->assertSame('tableau', $elements[0]['entree']['cle']);
        $this->assertSame(array_keys(self::MENUS), array_column(array_slice($elements, 1), 'cle'));
        foreach (array_slice($elements, 1) as $element) {
            $this->assertSame(self::MENUS[$element['cle']], array_column($element['entrees'], 'cle'));
        }

        // Chaque ancien onglet est dans le nouveau menu, à la même URL (« Mes brouillons » : même page, filtrée).
        $nouveau = collect($menu)->flatten(1);
        $anciens = $this->ancienMenu($user);
        $this->assertCount(22, $anciens);
        foreach ($anciens as $ancien) {
            $cle = $ancien['cle'] === 'brouillons' ? 'mes_brouillons' : $ancien['cle'];
            $entree = $nouveau->firstWhere('cle', $cle);
            $this->assertNotNull($entree, $ancien['cle']);
            $this->assertSame(parse_url(MenuBackoffice::url($ancien), PHP_URL_PATH), parse_url(MenuBackoffice::url($entree), PHP_URL_PATH), $ancien['cle']);
        }

        // Aucun lien mort : chaque entrée s'affiche dans le menu et répond.
        $page = $this->get('/admin')->assertOk()->assertSee('<span class="adm-libelle">Tarification</span>', false)
            ->assertSee('<span class="adm-libelle">Location des boutiques</span>', false)->assertSee('<span class="adm-libelle">RAAGA Invest</span>', false)
            ->assertSee('<span class="adm-libelle">Site public</span>', false)->assertSee('<span class="adm-libelle">Mes soumissions</span>', false);
        foreach ($nouveau as $entree) {
            $url = MenuBackoffice::url($entree);
            $page->assertSee('href="'.$url.'"', false);
            $this->get($url)->assertOk();
        }
    }

    public function test_badges_remontes_sur_le_menu_parent(): void
    {
        $this->connecterBackoffice(Role::Regulateur);
        // Une unité rejetée : badge « Unités » (Tarification) et « Rejetés » (Mes soumissions), sommés sur le parent.
        Unite::query()->create(['code' => 'FORFAIT', 'libelle' => 'Forfait', 'actif' => true, 'statut' => StatutTypeBoutique::Rejete]);

        $html = $this->get('/admin')->assertOk()->getContent();
        foreach (['tarification' => 'Tarification', 'soumissions' => 'Mes soumissions'] as $cle => $libelle) {
            $this->assertMatchesRegularExpression('#aria-controls="sous-menu-'.$cle.'".*?<span class="adm-libelle">'.$libelle.'</span>\s*<span class="adm-compteur">1<#s', $html);
        }
        $this->assertMatchesRegularExpression('#href="'.preg_quote(route('admin.brouillons', ['vue' => 'rejetes']), '#').'"[^>]*>\s*<span class="adm-libelle">Rejetés</span>\s*<span class="adm-compteur">1<#', $html);
        // Rien à traiter ailleurs : pas de badge sur RAAGA Invest.
        $this->assertDoesNotMatchRegularExpression('#<span class="adm-libelle">RAAGA Invest</span>\s*<span class="adm-compteur">#', $html);
    }

    public function test_menu_de_la_page_en_cours_ouvert_et_sous_menu_actif(): void
    {
        $this->connecterBackoffice(Role::Regulateur);
        $actif = fn (string $url) => '#href="'.preg_quote($url, '#').'" class="bo-lien adm-sous-lien bo-lien-actif"\s+aria-current="page"#';

        $html = $this->get(route('admin.cautions'))->assertOk()->getContent();
        $this->assertStringContainsString('id="sous-menu-location" class="adm-deroulant-corps est-deplie"', $html);
        $this->assertStringContainsString('id="sous-menu-invest" class="adm-deroulant-corps"', $html);
        $this->assertMatchesRegularExpression($actif(route('admin.cautions')), $html);

        // Grille tarifaire (sous la route des campagnes) : seule « Grille tarifaire » est active, dans Tarification.
        $html = $this->get(route('admin.boutiques.grilles'))->assertOk()->getContent();
        $this->assertStringContainsString('id="sous-menu-tarification" class="adm-deroulant-corps est-deplie"', $html);
        $this->assertStringContainsString('id="sous-menu-location" class="adm-deroulant-corps"', $html);
        $this->assertSame(1, substr_count($html, 'bo-lien-actif'));

        // « Mes soumissions » : le filtre choisit le sous-menu actif ; sans filtre, « Brouillons ».
        $html = $this->get(route('admin.brouillons', ['vue' => 'rejetes']))->assertOk()->assertSee('data-vue="rejetes"', false)->getContent();
        $this->assertMatchesRegularExpression($actif(route('admin.brouillons', ['vue' => 'rejetes'])), $html);
        $this->assertSame(1, substr_count($html, 'bo-lien-actif'));
        $html = $this->get(route('admin.brouillons'))->assertOk()->getContent();
        $this->assertMatchesRegularExpression($actif(route('admin.brouillons', ['vue' => 'brouillons'])), $html);
    }

    public function test_mes_soumissions_filtre_brouillons_rejetes_et_en_attente(): void
    {
        $this->connecterBackoffice(Role::Regulateur);
        foreach ([['BROUIL', StatutTypeBoutique::Brouillon], ['REJET', StatutTypeBoutique::Rejete], ['ATTENTE', StatutTypeBoutique::AValider]] as [$code, $statut]) {
            Unite::query()->create(['code' => $code, 'libelle' => 'Unité '.$code, 'actif' => true, 'statut' => $statut]);
        }

        $this->get(route('admin.brouillons', ['vue' => 'brouillons']))->assertOk()->assertSee('BROUIL · Unité BROUIL')->assertDontSee('REJET · ')->assertDontSee('ATTENTE · ');
        $this->get(route('admin.brouillons', ['vue' => 'rejetes']))->assertOk()->assertSee('REJET · Unité REJET')->assertSee('1 élément rejeté')->assertDontSee('BROUIL · ')->assertDontSee('ATTENTE · ');
        $this->get(route('admin.brouillons', ['vue' => 'en_attente']))->assertOk()->assertSee('En attente de validation')->assertSee('ATTENTE · Unité ATTENTE')
            ->assertDontSee('BROUIL · ')->assertDontSee('data-tout-soumettre', false)->assertDontSee('Motif du rejet');
        // Sans filtre (ancien lien) : brouillons et rejetés, comme avant.
        $this->get(route('admin.brouillons'))->assertOk()->assertSee('BROUIL · Unité BROUIL')->assertSee('REJET · Unité REJET')->assertDontSee('ATTENTE · ');
    }

    public function test_finances_des_sites_et_autres_profils_inchanges(): void
    {
        $this->connecterBackoffice(Role::Regulateur);
        $this->get(route('admin.sites.finances.liste'))->assertOk()->assertSee('Finances des sites')->assertSee('data-finances-sites', false);

        // Le GESTIONNAIRE ne voit jamais les finances ; les autres profils gardent la définition commune.
        $this->connecterBackoffice(Role::GestionnaireSites);
        $this->get(route('admin.sites.finances.liste'))->assertForbidden();
        foreach ([Role::SuperAdmin, Role::GestionnaireSites] as $role) {
            $user = $this->connecterBackoffice($role);
            $this->assertSame(collect($this->ancienMenu($user))->pluck('cle')->all(), collect(MenuBackoffice::pour($user))->flatten(1)->pluck('cle')->all());
            $this->get('/admin')->assertOk()->assertDontSee('sous-menu-tarification', false)->assertDontSee('Mes soumissions');
        }
    }
}
