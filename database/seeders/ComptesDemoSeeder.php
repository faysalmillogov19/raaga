<?php

namespace Database\Seeders;

use App\Enums\Role;
use App\Enums\StatutInstitution;
use App\Models\Institution;
use App\Models\Role as RoleModel;
use App\Models\User;
use App\Support\VerrouEnvironnement;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

/**
 * Comptes de démonstration (mot de passe = adresse e-mail) : jamais en production. Lancé avec APP_ENV=production, ce
 * seeder refuse de s'exécuter ; « php artisan raaga:comptes-demo --desactiver » désactive ceux qui existeraient déjà.
 *
 * Créés s'ils manquent ; s'ils existent, seul leur rôle est remis à jour (jamais le mot de passe, le nom ni le TOTP).
 * Back-office : sans TOTP enrôlé, la double authentification n'est dispensée qu'en local avec
 * RAAGA_TOTP_FACULTATIF_EN_LOCAL=true. Autres rôles : {prefixe}@raaga.test. Institutionnels : signataire@raaga.test et
 * lecteur@raaga.test, rattachés à « Institution de démonstration ».
 */
class ComptesDemoSeeder extends Seeder
{
    use WithoutModelEvents;

    /** Domaine des adresses de démonstration. */
    public const DOMAINE = '@raaga.test';

    /** Comptes back-office de démonstration : [nom, e-mail, rôle]. */
    public const BACKOFFICE = [
        ['Aminata SAWADOGO', 'superadmin@raaga.test', Role::SuperAdmin],
        ['Issouf KABORÉ', 'superadmin2@raaga.test', Role::SuperAdmin],
        ['Rasmata OUÉDRAOGO', 'admin@raaga.test', Role::Admin],
        ['Mariam TRAORÉ', 'validation@raaga.test', Role::Validateur],
        ['Boukary ZONGO', 'validation2@raaga.test', Role::Validateur],
        ['Clarisse SOMÉ', 'regulation@raaga.test', Role::Regulateur],
        ['Salif OUÉDRAOGO', 'sites@raaga.test', Role::GestionnaireSites],
        ['Adama SANOU', 'paiements@raaga.test', Role::ControleurPaiements],
    ];

    /** Comptes institutionnels de démonstration, rattachés à l'institution de démonstration : [nom, e-mail, rôle]. */
    public const INSTITUTIONNELS = [
        ['Awa COMPAORÉ', 'signataire@raaga.test', Role::InstitutionSignataire],
        ['Paul ILBOUDO', 'lecteur@raaga.test', Role::InstitutionLecteur],
    ];

    public function run(): void
    {
        VerrouEnvironnement::exigerHorsProduction('comptes de démonstration (mot de passe = adresse e-mail)');

        foreach (self::BACKOFFICE as [$nom, $email, $role]) {
            $this->compte($nom, $email, $role);
        }

        // Institution de démonstration (active) et ses deux comptes : signataire et lecteur.
        $institution = Institution::query()->firstOrCreate(['denomination' => 'Institution de démonstration'], [
            'sigle' => 'DEMO', 'forme_juridique' => 'SA', 'statut' => StatutInstitution::Active, 'adresse' => 'Ouagadougou',
        ]);
        foreach (self::INSTITUTIONNELS as [$nom, $email, $role]) {
            $this->compte($nom, $email, $role, $institution);
        }

        foreach (Role::cases() as $role) {
            // Les comptes institutionnels sont rattachés à une institution (ci-dessus, ou par invitation).
            if ($role->estBackoffice() || $role->estInstitutionnel()) {
                continue;
            }
            $this->compte($role->label(), $role->prefix().self::DOMAINE, $role);
        }
    }

    /** Crée le compte s'il manque (mot de passe = adresse e-mail) ; sinon remet seulement son rôle (et son institution) à jour. */
    private function compte(string $nom, string $email, Role $role, ?Institution $institution = null): void
    {
        $user = User::query()->firstOrNew(['email' => $email]);
        if (! $user->exists) {
            $user->forceFill(['name' => $nom, 'password' => $email, 'email_verified_at' => now()]);
        }
        $user->forceFill(['role_id' => RoleModel::pour($role)->id, ...($institution ? ['institution_id' => $institution->id] : [])])->save();
    }
}
