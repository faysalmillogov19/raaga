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

    /**
     * Seed the application's database.
     *
     * - Back-office : 2 SUPER_ADMIN, 1 ADMIN, 2 VALIDATEUR, 1 REGULATEUR (mot de passe ci-dessus).
     *   Sans TOTP enrôlé : la double authentification n'est dispensée qu'en local avec
     *   RAAGA_TOTP_FACULTATIF_EN_LOCAL=true ; sinon l'enrôlement est demandé à la première connexion.
     * - Autres rôles : un compte {prefixe}@raaga.test, mot de passe « password ».
     * À changer immédiatement hors environnement local.
     */
    public function run(): void
    {
        RoleModel::synchroniser();
        $this->call(ParametresPlateformeSeeder::class);

        $backoffice = [
            ['Aminata SAWADOGO', 'superadmin@raaga.test', Role::SuperAdmin],
            ['Issouf KABORÉ', 'superadmin2@raaga.test', Role::SuperAdmin],
            ['Rasmata OUÉDRAOGO', 'admin@raaga.test', Role::Admin],
            ['Mariam TRAORÉ', 'validation@raaga.test', Role::Validateur],
            ['Boukary ZONGO', 'validation2@raaga.test', Role::Validateur],
            ['Clarisse SOMÉ', 'regulation@raaga.test', Role::Regulateur],
            ['Salif OUÉDRAOGO', 'sites@raaga.test', Role::GestionnaireSites],
        ];

        foreach ($backoffice as [$nom, $email, $role]) {
            User::factory()->role($role)->create([
                'name' => $nom,
                'email' => $email,
                'password' => self::MOT_DE_PASSE_BACKOFFICE,
            ]);
        }

        foreach (Role::cases() as $role) {
            if ($role->estBackoffice()) {
                continue;
            }

            User::factory()->role($role)->create([
                'name' => $role->label(),
                'email' => $role->prefix().'@raaga.test',
            ]);
        }
    }
}
