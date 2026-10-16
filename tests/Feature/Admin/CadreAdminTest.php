<?php

namespace Tests\Feature\Admin;

use App\Enums\Role;
use App\Support\MenuBackoffice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\ConnexionBackoffice;
use Tests\TestCase;

/** Charte du back-office : cadre unique sur toutes les pages /admin, menus selon les droits, thème mémorisé. */
class CadreAdminTest extends TestCase
{
    use ConnexionBackoffice, RefreshDatabase;

    public static function profils(): array
    {
        return array_map(fn (Role $r) => [$r], ['SUPER_ADMIN' => Role::SuperAdmin, 'ADMIN' => Role::Admin, 'VALIDATEUR' => Role::Validateur,
            'REGULATEUR' => Role::Regulateur, 'GESTIONNAIRE_SITES' => Role::GestionnaireSites]);
    }

    #[DataProvider('profils')]
    public function test_le_cadre_est_present_sur_toutes_les_pages_admin(Role $role): void
    {
        $user = $this->connecterBackoffice($role);
        $pages = collect(MenuBackoffice::pour($user))->flatten(1)->map(fn ($e) => MenuBackoffice::url($e))
            ->push(route('admin.profil'), route('admin.recherche', ['q' => 'RAA']));

        foreach ($pages as $url) {
            $this->get($url)->assertOk()
                ->assertSee('data-layout="admin"', false)->assertSee('class="adm-bandeau"', false)->assertSee('class="adm-lateral"', false)
                ->assertSee('class="adm-haut"', false)->assertSee('id="recherche-globale"', false)->assertSee('class="adm-fil"', false)
                ->assertSee('class="adm-titre bo-titre"', false)->assertSee($user->name);
        }
    }

    #[DataProvider('profils')]
    public function test_la_barre_laterale_suit_les_droits(Role $role): void
    {
        $user = $this->connecterBackoffice($role);
        $page = $this->get('/admin')->assertOk();
        $menu = MenuBackoffice::pour($user);

        foreach (MenuBackoffice::definition() as $groupe => $entrees) {
            isset($menu[$groupe])
                ? $page->assertSee('<p class="adm-groupe">'.e(__('admin.groupes.'.$groupe)).'</p>', false)
                : $page->assertDontSee('<p class="adm-groupe">'.e(__('admin.groupes.'.$groupe)).'</p>', false);
            foreach ($entrees as $entree) {
                $lien = 'href="'.MenuBackoffice::url($entree).'" class="bo-lien';
                // Le RÉGULATEUR a son propre regroupement : l'entrée est cherchée dans tout son menu.
                collect($menu)->flatten(1)->contains('cle', $entree['cle']) ? $page->assertSee($lien, false) : $page->assertDontSee($lien, false);
            }
        }
        // Lien actif : le tableau de bord, en gras sur fond var(--side-active).
        $this->assertMatchesRegularExpression('#href="'.preg_quote(route('admin.tableau'), '#').'" class="bo-lien bo-lien-actif"\s+aria-current="page"#', $page->getContent());
    }

    public function test_le_theme_est_memorise_pour_l_utilisateur(): void
    {
        $user = $this->connecterBackoffice(Role::Validateur);
        $this->get('/admin')->assertSee('class="site-public bo adm theme-clair"', false);

        $this->postJson(route('admin.preferences.theme'), ['theme' => 'foret'])->assertOk()->assertJson(['theme' => 'foret']);
        $this->assertSame('foret', $user->fresh()->theme_backoffice);
        $this->get(route('admin.sites'))->assertSee('class="site-public bo adm theme-foret"', false);

        // Sans JavaScript (formulaire), valeur inconnue refusée.
        $this->post(route('admin.preferences.theme'), ['theme' => 'clair'])->assertRedirect()->assertSessionHas('succes');
        $this->assertSame('clair', $user->fresh()->theme_backoffice);
        $this->post(route('admin.preferences.theme'), ['theme' => 'rose'])->assertSessionHasErrors('theme');
        $this->assertSame('clair', $user->fresh()->theme_backoffice);

        // Le choix appartient à l'utilisateur : les autres comptes gardent le thème clair.
        $autre = $this->connecterBackoffice(Role::Regulateur);
        $this->assertNull($autre->fresh()->theme_backoffice);
        $this->get('/admin')->assertSee('theme-clair', false);
    }
}
