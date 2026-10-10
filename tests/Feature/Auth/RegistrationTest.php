<?php

namespace Tests\Feature\Auth;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RegistrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_registration_screen_can_be_rendered(): void
    {
        $response = $this->get('/register');

        $response->assertStatus(200);
    }

    public function test_new_users_can_register(): void
    {
        $response = $this->post('/register', [
            'name' => 'Test User',
            'email' => 'test@example.com',
            'role' => Role::Investisseur->value,
            'password' => 'password',
            'password_confirmation' => 'password',
        ]);

        $this->assertAuthenticated();
        $response->assertRedirect(route('dashboard', absolute: false));
        $this->assertSame(Role::Investisseur, User::first()->codeRole());
    }

    public function test_users_cannot_register_as_admin_or_superadmin(): void
    {
        foreach ([Role::Admin, Role::SuperAdmin] as $role) {
            $this->post('/register', [
                'name' => 'Test User',
                'email' => 'test@example.com',
                'role' => $role->value,
                'password' => 'password',
                'password_confirmation' => 'password',
            ])->assertSessionHasErrors('role');
        }

        $this->assertGuest();
        $this->assertDatabaseCount('users', 0);
    }
}
