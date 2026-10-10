<?php

namespace Tests\Support;

use App\Enums\Role;
use App\Models\User;

/**
 * Simule une session back-office complète (mot de passe + TOTP validés).
 */
trait ConnexionBackoffice
{
    protected function connecterBackoffice(Role $role, array $attributs = []): User
    {
        $user = User::factory()->role($role)->create($attributs);

        $this->actingAs($user)->withSession(['backoffice' => [
            'totp_ok' => true,
            'debut' => now()->timestamp,
            'activite' => now()->timestamp,
        ]]);

        return $user;
    }
}
