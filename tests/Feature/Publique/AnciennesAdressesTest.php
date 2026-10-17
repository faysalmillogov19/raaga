<?php

namespace Tests\Feature\Publique;

use App\Enums\Role;
use App\Models\User;
use App\Services\Auth\OtpService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AnciennesAdressesTest extends TestCase
{
    use RefreshDatabase;

    public function test_les_adresses_breeze_redirigent_definitivement_vers_les_parcours_raaga(): void
    {
        $this->get('/login')->assertStatus(301)->assertRedirect('/connexion');
        $this->get('/register')->assertStatus(301)->assertRedirect('/inscription');
        $this->get('/profile')->assertStatus(301)->assertRedirect('/espace/profil');
    }

    public function test_les_autres_pages_breeze_n_existent_plus(): void
    {
        foreach (['/forgot-password', '/reset-password/jeton', '/verify-email', '/confirm-password'] as $page) {
            $this->get($page)->assertNotFound();
        }

        $user = User::factory()->create();
        $this->actingAs($user)->delete('/profile');
        $this->assertModelExists($user);
    }

    public function test_la_deconnexion_fonctionne(): void
    {
        $this->actingAs(User::factory()->create())->post('/logout')->assertRedirect('/');

        $this->assertGuest();
    }

    public function test_un_profil_d_espace_securise_ne_peut_pas_se_connecter_par_sms(): void
    {
        foreach ([Role::Validateur, Role::InstitutionSignataire] as $i => $role) {
            $telephone = '+2267000000'.$i;
            User::factory()->role($role)->create(['telephone' => $telephone]);

            $this->post('/connexion/telephone', ['telephone' => $telephone])
                ->assertSessionHasErrors(['telephone' => __('public.compte.connexion.espace_securise')]);

            $this->assertFalse(app(OtpService::class)->aUnCodeActif($telephone, 'connexion'));
            $this->assertGuest();
        }
    }

    public function test_l_ancienne_adresse_du_guichet_renvoie_au_back_office(): void
    {
        // Agent de guichet : profil du back-office depuis le 17/10/2026.
        $this->actingAs(User::factory()->role(Role::AgentGuichet)->create())->get('/guichet')->assertRedirect('/admin');
        $this->assertSame('admin.tableau', Role::AgentGuichet->dashboardRoute());
    }
}
