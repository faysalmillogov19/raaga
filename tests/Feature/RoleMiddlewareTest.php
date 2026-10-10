<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class RoleMiddlewareTest extends TestCase
{
    use RefreshDatabase;

    public static function roles(): array
    {
        return array_map(fn (Role $role) => [$role], array_combine(
            array_map(fn (Role $role) => $role->value, Role::cases()),
            Role::cases(),
        ));
    }

    /**
     * Rôles hors espaces sécurisés (back-office et institutionnels ont leurs propres tests : connexion avec TOTP).
     */
    public static function rolesHorsBackoffice(): array
    {
        return array_filter(self::roles(), fn (array $args) => ! $args[0]->estEspaceSecurise());
    }

    #[DataProvider('rolesHorsBackoffice')]
    public function test_dashboard_redirects_to_role_dashboard(Role $role): void
    {
        $user = User::factory()->role($role)->create();

        $this->actingAs($user)
            ->get('/dashboard')
            ->assertRedirect(route($role->dashboardRoute()));

        $this->actingAs($user)
            ->get(route($role->dashboardRoute()))
            ->assertOk()
            ->assertSee($role->label());
    }

    public function test_les_profils_back_office_sont_envoyes_vers_le_back_office(): void
    {
        foreach (Role::backoffice() as $role) {
            $this->actingAs(User::factory()->role($role)->create())
                ->get('/dashboard')
                ->assertRedirect(route('admin.tableau'));
        }
    }

    #[DataProvider('roles')]
    public function test_users_cannot_access_other_role_dashboards(Role $role): void
    {
        $user = User::factory()->role($role)->create();

        foreach (Role::cases() as $other) {
            // Entre profils d'un même espace sécurisé (/admin, /institutionnel), l'espace est commun : couvert par leurs tests.
            if ($other === $role || ($role->estBackoffice() && $other->estBackoffice())
                || ($role->estInstitutionnel() && $other->estInstitutionnel())) {
                continue;
            }

            $this->actingAs($user)
                ->get(route($other->dashboardRoute()))
                ->assertForbidden();
        }
    }

    public function test_les_autres_profils_n_ont_pas_acces_au_back_office(): void
    {
        foreach (Role::cases() as $role) {
            if ($role->estBackoffice()) {
                continue;
            }

            $this->actingAs(User::factory()->role($role)->create())
                ->get('/admin')
                ->assertForbidden();
        }
    }

    public function test_guests_are_redirected_to_login(): void
    {
        $this->get(route('citoyen.dashboard'))->assertRedirect(route('connexion'));
        $this->get('/admin')->assertRedirect(route('admin.connexion'));
    }
}
