<?php

namespace Tests\Support;

use App\Enums\Role;
use App\Models\User;

/**
 * Trois acteurs du workflow de paramétrage et bascule de session de l'un à l'autre
 * (session back-office complète : mot de passe + TOTP validés).
 */
trait ActeursParametrage
{
    protected User $regulateur;

    protected User $validateur;

    protected User $superAdmin;

    protected function creerActeurs(): void
    {
        $this->regulateur = User::factory()->role(Role::Regulateur)->create();
        $this->validateur = User::factory()->role(Role::Validateur)->create();
        $this->superAdmin = User::factory()->role(Role::SuperAdmin)->create();
    }

    protected function en(User $user): static
    {
        $this->app['auth']->forgetGuards();

        return $this->actingAs($user)->withSession(['backoffice' => [
            'totp_ok' => true,
            'debut' => now()->timestamp,
            'activite' => now()->timestamp,
        ]]);
    }
}
