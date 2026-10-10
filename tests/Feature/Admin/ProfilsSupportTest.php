<?php

namespace Tests\Feature\Admin;

use App\Enums\Role;
use App\Enums\StatutTranche;
use App\Http\Controllers\Admin\DividendesController;
use App\Http\Controllers\Admin\TranchesController;
use App\Models\DistributionDividende;
use App\Models\Tranche;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\ConnexionBackoffice;
use Tests\TestCase;

/**
 * SUPER_ADMIN et ADMIN sont des profils de support : aucune écriture sur les tranches, les dividendes,
 * les simulations ni les opérations ; l'ADMIN n'accède ni aux comptes back-office, ni aux approbations,
 * ni à la sécurité, ni aux paramètres.
 */
class ProfilsSupportTest extends TestCase
{
    use ConnexionBackoffice, RefreshDatabase;

    private function tranche(): Tranche
    {
        return Tranche::create([
            ...TranchesController::valeursParDefaut(),
            'nom' => 'Tranche 1',
            'statut' => StatutTranche::Brouillon,
            'version' => 0,
            'cree_par' => User::factory()->role(Role::Regulateur)->create()->id,
        ]);
    }

    public static function profilsSupport(): array
    {
        return ['SUPER_ADMIN' => [Role::SuperAdmin], 'ADMIN' => [Role::Admin]];
    }

    #[DataProvider('profilsSupport')]
    public function test_aucune_ecriture_sur_les_tranches_les_dividendes_ni_les_operations(Role $role): void
    {
        $tranche = $this->tranche();
        $distribution = DistributionDividende::query()->create([
            ...DividendesController::valeursParDefaut(),
            'ag_date' => '2026-06-30', 'ag_pv_numero' => 'PV-1', 'resultat_distribuable' => 1000, 'montant_global' => 500,
            'date_arrete' => '2026-07-15', 'date_mise_en_paiement' => '2026-08-01', 'statut' => 'BROUILLON', 'version' => 0,
            'cree_par' => $tranche->cree_par,
        ]);
        $this->connecterBackoffice($role);

        foreach ([
            ['get', route('admin.tranches.create')],
            ['post', route('admin.tranches.store')],
            ['put', route('admin.tranches.update', $tranche)],
            ['post', route('admin.tranches.soumettre', $tranche)],
            ['post', route('admin.tranches.confirmer', $tranche)],
            ['post', route('admin.tranches.ouvrir', $tranche)],
            ['post', route('admin.tranches.avenant.store', $tranche)],
            ['get', route('admin.dividendes.create')],
            ['post', route('admin.dividendes.store')],
            ['put', route('admin.dividendes.update', $distribution)],
            ['post', route('admin.dividendes.soumettre', $distribution)],
            ['post', route('admin.dividendes.confirmer', $distribution)],
            ['get', route('admin.dividendes.simulation', $distribution)],
            ['get', route('admin.kyc')],
            ['get', route('admin.conformite')],
            ['get', route('admin.souscriptions')],
            ['get', route('admin.remboursements')],
            ['get', route('admin.registre')],
        ] as [$methode, $url]) {
            $this->{$methode}($url, ['motif' => 'Tentative interdite', 'confirmation' => 'CONFIRMER'])
                ->assertForbidden();
        }
    }

    public function test_l_admin_n_accede_ni_aux_comptes_back_office_ni_aux_approbations_ni_a_la_securite_ni_aux_parametres(): void
    {
        $cible = User::factory()->role(Role::Validateur)->create();
        $tranche = $this->tranche();
        $this->connecterBackoffice(Role::Admin);

        foreach (['/admin/utilisateurs', '/admin/utilisateurs/inviter', "/admin/utilisateurs/{$cible->id}",
            '/admin/approbations', '/admin/securite', '/admin/connexions', '/admin/parametres', '/admin/audit',
            '/admin/tranches', "/admin/tranches/{$tranche->id}", '/admin/dividendes', '/admin/simulations', '/admin/referentiels'] as $url) {
            $this->get($url)->assertForbidden();
        }

        $this->post('/admin/utilisateurs', ['nom' => 'X', 'email' => 'x@raaga.test', 'role' => 'superadmin'])->assertForbidden();
        $this->post("/admin/utilisateurs/{$cible->id}/role", ['role' => 'admin'])->assertForbidden();
        $this->assertDatabaseMissing('users', ['email' => 'x@raaga.test']);
    }

    public function test_l_admin_accede_aux_onglets_de_support(): void
    {
        $this->connecterBackoffice(Role::Admin);

        $this->get('/admin')->assertOk()->assertSee('bo-badge bo-badge-admin', false)->assertSee('ADMIN');
        foreach (['/admin/assistance', '/admin/demandes', '/admin/contenus'] as $url) {
            $this->get($url)->assertOk();
        }
    }

    public function test_le_super_admin_consulte_les_parametres_en_lecture(): void
    {
        $tranche = $this->tranche();
        $this->connecterBackoffice(Role::SuperAdmin);

        $this->get('/admin/parametres')->assertOk()->assertSee('Lecture seule')->assertSee(route('admin.tranches'), false);
        $this->get("/admin/tranches/{$tranche->id}")->assertOk()->assertDontSee('Modifier les paramètres')->assertDontSee('Soumettre à confirmation');
    }

    public function test_le_super_admin_peut_inviter_un_admin(): void
    {
        Notification::fake();
        $this->connecterBackoffice(Role::SuperAdmin);

        $this->get('/admin/utilisateurs/inviter')->assertOk()->assertSee('ADMIN');
        $this->post('/admin/utilisateurs', ['nom' => 'Rasmata OUÉDRAOGO', 'email' => 'rasmata@raaga.test', 'role' => 'admin'])
            ->assertSessionHas('succes');

        $this->assertSame(Role::Admin, User::query()->where('email', 'rasmata@raaga.test')->firstOrFail()->codeRole());
    }
}
