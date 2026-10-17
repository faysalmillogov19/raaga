<?php

namespace Tests\Feature\Admin;

use App\Enums\Role;
use App\Models\User;
use App\Support\MenuBackoffice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\ConnexionBackoffice;
use Tests\TestCase;

/**
 * Chaque profil ne voit que ses menus, et toute route non autorisée renvoie 403 côté serveur.
 */
class NavigationTest extends TestCase
{
    use ConnexionBackoffice, RefreshDatabase;

    /** Écrans hors menu mais accessibles en lecture (le SUPER_ADMIN y arrive par « Paramètres (lecture) »). */
    private const LECTURE_HORS_MENU = [
        'superadmin' => ['tranches', 'investissement', 'boutiques', 'dividendes', 'simulations', 'referentiels'],
        // RÉGULATEUR : « Mes brouillons » est atteint par « Mes soumissions › Brouillons » (même page, filtrée).
        'regulateur' => ['brouillons'],
    ];

    /** Écrans autorisés par profil (d'après le tableau des droits) ; le menu les montre tous, sauf les pages en préparation. */
    private const MENUS = [
        'superadmin' => ['tableau', 'sites', 'types', 'niveaux', 'zones', 'boutiques_referentiel', 'capacites', 'grille', 'imports_sites', 'imports_boutiques', 'assistance', 'demandes', 'contenus', 'institutions', 'registre_a', 'approbations', 'utilisateurs', 'phase', 'plateforme', 'securite', 'audit', 'parametres', 'connexions', 'unites', 'moyens', 'delais'],
        'admin' => ['tableau', 'assistance', 'demandes', 'contenus', 'institutions', 'plateforme', 'interets', 'kyc', 'guichet', 'photos_personnel'],
        'agent_guichet' => ['tableau', 'guichet'],
        'controleur_paiements' => ['tableau', 'paiements', 'remboursements'],
        'gestionnaire_sites' => ['tableau', 'brouillons', 'sites', 'types', 'niveaux', 'zones', 'boutiques_referentiel', 'capacites', 'imports_sites', 'imports_boutiques'],
        'validateur' => ['tableau', 'a_valider', 'a_valider_attributions', 'attributions_a_faire', 'historique', 'a_valider_sites', 'a_valider_finances', 'a_valider_grilles', 'a_valider_referentiels', 'a_valider_campagnes', 'a_valider_demarches', 'a_valider_autorisations', 'a_valider_imports', 'a_valider_paiements', 'a_valider_cautions', 'a_valider_identites', 'a_valider_cartes', 'cautions', 'tranches', 'investissement', 'sites', 'boutiques_referentiel', 'imports_sites', 'imports_boutiques', 'boutiques', 'dividendes', 'simulations', 'dossiers_kyb', 'conventions', 'appels_fonds', 'registre_a', 'conformite', 'registre', 'audit', 'observations'],
        'regulateur' => ['tableau', 'mes_brouillons', 'rejetes', 'en_attente', 'finances', 'tranches', 'investissement', 'interets', 'sites', 'boutiques_referentiel', 'capacites', 'grille', 'boutiques', 'dividendes', 'simulations', 'dossiers_kyb', 'conventions', 'appels_fonds', 'registre_a', 'kyc', 'conformite', 'souscriptions', 'remboursements', 'registre', 'audit', 'regulation', 'observations', 'referentiels', 'phase', 'unites', 'moyens', 'delais', 'cautions'],
    ];

    public static function profils(): array
    {
        return [
            'SUPER_ADMIN' => [Role::SuperAdmin],
            'ADMIN' => [Role::Admin],
            'VALIDATEUR' => [Role::Validateur],
            'REGULATEUR' => [Role::Regulateur],
            'GESTIONNAIRE_SITES' => [Role::GestionnaireSites],
            'CONTROLEUR_PAIEMENTS' => [Role::ControleurPaiements],
            'AGENT_GUICHET' => [Role::AgentGuichet],
        ];
    }

    #[DataProvider('profils')]
    public function test_chaque_profil_ne_voit_que_ses_menus(Role $role): void
    {
        $user = $this->connecterBackoffice($role);

        $visibles = collect(MenuBackoffice::pour($user))->flatten(1)->pluck('cle')->sort()->values()->all();
        $menu = collect(self::MENUS[$role->value])->diff(MenuBackoffice::EN_PREPARATION)->values()->all();
        $attendus = collect($menu)->sort()->values()->all();
        $this->assertSame($attendus, $visibles);

        $page = $this->get('/admin')->assertOk()->assertSee('<span class="adm-profil-role">'.e($role->label()).'</span>', false);

        foreach (MenuBackoffice::definition() as $entrees) {
            foreach ($entrees as $entree) {
                $lien = 'href="'.MenuBackoffice::url($entree).'" class="bo-lien';
                in_array($entree['cle'], $menu, true)
                    ? $page->assertSee($lien, false)
                    : $page->assertDontSee($lien, false);
            }
        }
    }

    #[DataProvider('profils')]
    public function test_chaque_route_non_autorisee_renvoie_403(Role $role): void
    {
        $this->connecterBackoffice($role);

        foreach (MenuBackoffice::definition() as $entrees) {
            foreach ($entrees as $entree) {
                $reponse = $this->get(MenuBackoffice::url($entree));
                $autorises = [...self::MENUS[$role->value], ...(self::LECTURE_HORS_MENU[$role->value] ?? [])];

                in_array($entree['cle'], $autorises, true)
                    ? $reponse->assertOk()
                    : $reponse->assertForbidden();
            }
        }
    }

    public function test_le_regulateur_voit_ses_sections_en_lecture_seule(): void
    {
        $this->connecterBackoffice(Role::Regulateur);

        $this->get(route('admin.registre'))
            ->assertOk()
            ->assertSee('Lecture seule')
            ->assertSee('Exporter le registre');
    }

    public function test_le_journal_des_connexions_est_reserve_au_super_admin(): void
    {
        $this->connecterBackoffice(Role::SuperAdmin);
        $this->get('/admin/connexions')->assertOk()->assertSee('Journal des connexions');
    }

    public function test_un_visiteur_non_connecte_est_envoye_vers_la_connexion_back_office(): void
    {
        foreach (['/admin', '/admin/tranches', '/admin/utilisateurs'] as $url) {
            $this->get($url)->assertRedirect(route('admin.connexion'));
        }
    }

    public function test_un_profil_back_office_sans_double_authentification_est_renvoye_a_la_connexion(): void
    {
        $user = User::factory()->role(Role::SuperAdmin)->create();

        // Connecté (par exemple via une autre porte) mais sans TOTP validé.
        $this->actingAs($user)->get('/admin')->assertRedirect(route('admin.connexion'));
        $this->assertGuest();
    }
}
