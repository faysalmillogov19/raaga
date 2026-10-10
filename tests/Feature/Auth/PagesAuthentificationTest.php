<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PagesAuthentificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_la_connexion_utilise_le_gabarit_public_en_francais(): void
    {
        $this->get('/login')
            ->assertOk()
            ->assertSee('BURKINA FASO')
            ->assertSee('Se connecter')
            ->assertSee('Adresse électronique')
            ->assertSee('Mot de passe oublié ?')
            ->assertSee('Rester connecté sur cet appareil')
            ->assertDontSee('Log in');
    }

    public function test_un_echec_de_connexion_affiche_un_message_en_francais(): void
    {
        $user = User::factory()->create();

        $this->from('/login')
            ->post('/login', ['email' => $user->email, 'password' => 'mauvais-mot-de-passe'])
            ->assertSessionHasErrors(['email' => 'Ces identifiants ne correspondent à aucun compte.']);

        $this->followingRedirects()
            ->from('/login')
            ->post('/login', ['email' => '', 'password' => ''])
            ->assertSee('Le champ « adresse électronique » est obligatoire.')
            ->assertSee('aria-invalid="true"', false);
    }

    public function test_les_autres_pages_d_authentification_sont_adaptees(): void
    {
        $this->get('/register')->assertOk()->assertSee('Créer mon compte')->assertSee('Je suis')->assertSee('Citoyen');
        $this->get('/forgot-password')->assertOk()->assertSee('Recevoir le lien');
        $this->get('/reset-password/jeton')->assertOk()->assertSee('Choisir un nouveau mot de passe');

        $this->actingAs(User::factory()->unverified()->create())
            ->get('/verify-email')->assertOk()->assertSee('Renvoyer le lien');

        $this->actingAs(User::factory()->create())
            ->get('/confirm-password')->assertOk()->assertSee('Confirmez votre mot de passe');
    }
}
