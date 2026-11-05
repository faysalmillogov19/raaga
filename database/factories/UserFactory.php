<?php

namespace Database\Factories;

use App\Enums\Role;
use App\Enums\StatutDocumentKyc;
use App\Enums\TypeDocumentKyc;
use App\Models\KycDocument;
use App\Models\Role as RoleModel;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'role_id' => fn () => RoleModel::pour(Role::Visiteur)->id,
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
        ];
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }

    /**
     * Attribue le rôle donné à l'utilisateur.
     */
    /**
     * Commerçant formalisé : IFU donné (8 chiffres et une lettre, unique) et attestation IFU validée (sans elle, depuis
     * le 05/11/2026, la carte de commerçant reste en attente).
     */
    public function avecIfu(): static
    {
        return $this->state(fn () => ['ifu' => sprintf('%08d', fake()->unique()->numberBetween(1, 99_999_999)).strtoupper(fake()->randomLetter())])
            ->afterCreating(fn (User $user) => KycDocument::query()->create(['user_id' => $user->id, 'type' => TypeDocumentKyc::Ifu, 'chemin_fichier' => 'kyc/'.$user->id.'/ifu.pdf',
                'nom_original' => 'ifu.pdf', 'mime' => 'application/pdf', 'taille' => 1000, 'empreinte_sha256' => str_repeat('0', 64),
                'statut' => StatutDocumentKyc::Valide, 'televerse_par' => $user->id, 'numero' => $user->ifu]));
    }

    public function role(Role $role): static
    {
        return $this->state(fn (array $attributes) => [
            'role_id' => RoleModel::pour($role)->id,
            // Citoyen : IFU obligatoire depuis le 03/11/2026 (8 chiffres et une lettre, unique).
        ]);
    }

    public function superAdmin(): static
    {
        return $this->role(Role::SuperAdmin);
    }

    public function admin(): static
    {
        return $this->role(Role::Admin);
    }

    public function porteurProjet(): static
    {
        return $this->role(Role::PorteurProjet);
    }

    public function investisseur(): static
    {
        return $this->role(Role::Investisseur);
    }
}
