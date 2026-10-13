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
     * - Rôles : table alignée sur l'enum (RoleSeeder) ; zones (ReferentielsSitesSeeder) et types de boutiques par défaut
     *   (TypesBoutiqueSeeder, appelé par ReferentielsSitesSeeder ; lançable seul, y compris en production).
     * - Comptes de démonstration : créés s'ils manquent, avec un mot de passe identique à leur adresse e-mail
     *   (exemple : sites@raaga.test / sites@raaga.test) ; s'ils existent, seul leur rôle est remis à jour
     *   (jamais le mot de passe, le nom ni le TOTP). Back-office : sans TOTP enrôlé, la double authentification
     *   n'est dispensée qu'en local avec RAAGA_TOTP_FACULTATIF_EN_LOCAL=true. Autres rôles : {prefixe}@raaga.test.
     * À changer immédiatement hors environnement local.
     */
    public function run(): void
    {
        $this->call(RoleSeeder::class);
        $this->call(ParametresPlateformeSeeder::class);
        $this->call(ReferentielsSitesSeeder::class);

        foreach (self::BACKOFFICE as [$nom, $email, $role]) {
            $this->compte($nom, $email, $role);
        }

        foreach (Role::cases() as $role) {
            // Les comptes institutionnels sont rattachés à une institution : ils se créent par invitation.
            if ($role->estBackoffice() || $role->estInstitutionnel()) {
                continue;
            }
            $this->compte($role->label(), $role->prefix().'@raaga.test', $role);
        }
    }

    /** Crée le compte s'il manque (mot de passe = adresse e-mail) ; sinon remet seulement son rôle à jour. */
    private function compte(string $nom, string $email, Role $role): void
    {
        $user = User::query()->firstOrNew(['email' => $email]);
        if (! $user->exists) {
            $user->forceFill(['name' => $nom, 'password' => $email, 'email_verified_at' => now()]);
        }
        $user->forceFill(['role_id' => RoleModel::pour($role)->id])->save();
    }
}
