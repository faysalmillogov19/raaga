<?php

namespace Database\Seeders;

use App\Enums\Role;
use App\Models\Role as RoleModel;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /** Mot de passe des comptes back-office de démonstration (12 caractères minimum). */
    public const MOT_DE_PASSE_BACKOFFICE = 'Raaga-Demo-2026!';

    /** Mot de passe des autres comptes de démonstration. */
    public const MOT_DE_PASSE_AUTRES = 'password';

    /** Comptes back-office de démonstration : [nom, e-mail, rôle]. */
    private const BACKOFFICE = [
        ['Aminata SAWADOGO', 'superadmin@raaga.test', Role::SuperAdmin],
        ['Issouf KABORÉ', 'superadmin2@raaga.test', Role::SuperAdmin],
        ['Rasmata OUÉDRAOGO', 'admin@raaga.test', Role::Admin],
        ['Mariam TRAORÉ', 'validation@raaga.test', Role::Validateur],
        ['Boukary ZONGO', 'validation2@raaga.test', Role::Validateur],
        ['Clarisse SOMÉ', 'regulation@raaga.test', Role::Regulateur],
        ['Salif OUÉDRAOGO', 'sites@raaga.test', Role::GestionnaireSites],
    ];

    /**
     * Seed the application's database. Se relance sans risque (« php artisan db:seed ») :
     *
     * - Rôles : table alignée sur l'enum (RoleSeeder).
     * - Comptes de démonstration : créés s'ils manquent ; s'ils existent, seul leur rôle est remis à jour
     *   (jamais le mot de passe, le nom ni le TOTP). Back-office : mot de passe ci-dessus ; sans TOTP enrôlé,
     *   la double authentification n'est dispensée qu'en local avec RAAGA_TOTP_FACULTATIF_EN_LOCAL=true.
     *   Autres rôles : un compte {prefixe}@raaga.test, mot de passe « password ».
     * À changer immédiatement hors environnement local.
     */
    public function run(): void
    {
        $this->call(RoleSeeder::class);
        $this->call(ParametresPlateformeSeeder::class);

        foreach (self::BACKOFFICE as [$nom, $email, $role]) {
            $this->compte($nom, $email, $role, self::MOT_DE_PASSE_BACKOFFICE);
        }

        foreach (Role::cases() as $role) {
            // Les comptes institutionnels sont rattachés à une institution : ils se créent par invitation.
            if ($role->estBackoffice() || $role->estInstitutionnel()) {
                continue;
            }
            $this->compte($role->label(), $role->prefix().'@raaga.test', $role, self::MOT_DE_PASSE_AUTRES);
        }
    }

    /** Crée le compte s'il manque ; sinon remet seulement son rôle à jour. */
    private function compte(string $nom, string $email, Role $role, string $motDePasse): void
    {
        $user = User::query()->firstOrNew(['email' => $email]);
        if (! $user->exists) {
            $user->forceFill(['name' => $nom, 'password' => $motDePasse, 'email_verified_at' => now()]);
        }
        $user->forceFill(['role_id' => RoleModel::pour($role)->id])->save();
    }
}
