<?php

namespace Tests\Feature\Admin;

use App\Enums\Role;
use App\Support\MenuBackoffice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\ConnexionBackoffice;
use Tests\TestCase;

/**
 * Pages d'attente : aucune entrée de menu ni aucun lien du tableau de bord n'y mène, pour aucun profil ; la route reste en
 * place, protégée par son droit, et répond « Page en préparation ».
 */
class PagesEnPreparationTest extends TestCase
{
    use ConnexionBackoffice, RefreshDatabase;

    public static function profils(): array
    {
        return array_map(fn (Role $r) => [$r], ['SUPER_ADMIN' => Role::SuperAdmin, 'ADMIN' => Role::Admin, 'VALIDATEUR' => Role::Validateur,
            'REGULATEUR' => Role::Regulateur, 'GESTIONNAIRE_SITES' => Role::GestionnaireSites, 'CONTROLEUR_PAIEMENTS' => Role::ControleurPaiements]);
    }

    public function test_les_pages_d_attente_sont_celles_du_controleur_de_sections(): void
    {
        $attente = collect(app('router')->getRoutes()->getRoutes())
            ->filter(fn ($r) => $r->getActionName() === 'App\Http\Controllers\Admin\SectionController@show')
            ->map(fn ($r) => $r->defaults['section'])->sort()->values()->all();
        $this->assertSame(collect(MenuBackoffice::EN_PREPARATION)->sort()->values()->all(), $attente);
    }

    #[DataProvider('profils')]
    public function test_aucun_lien_de_menu_ne_mene_a_une_page_d_attente(Role $role): void
    {
        $user = $this->connecterBackoffice($role);
        $this->assertSame([], collect(MenuBackoffice::pour($user))->flatten(1)->pluck('cle')->intersect(MenuBackoffice::EN_PREPARATION)->values()->all());

        $pages = [$this->get('/admin')->assertOk()->getContent()];
        if ($user->can('parametres.voir')) {
            $pages[] = $this->get(route('admin.parametres'))->assertOk()->getContent();
        }
        foreach (MenuBackoffice::EN_PREPARATION as $cle) {
            foreach ($pages as $html) {
                $this->assertStringNotContainsString('href="'.route('admin.'.$cle).'"', $html, $cle);
            }
            // La route reste en place : « Page en préparation » pour qui a le droit, 403 sinon.
            $user->can(MenuBackoffice::entree($cle)['droit']->value)
                ? $this->get(route('admin.'.$cle))->assertOk()->assertSee('Page en préparation')->assertSee('data-en-preparation', false)
                : $this->get(route('admin.'.$cle))->assertForbidden();
        }
    }
}
