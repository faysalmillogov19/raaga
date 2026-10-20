<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database. Se relance sans risque (« php artisan db:seed ») :
     *
     * - Rôles : table alignée sur l'enum (RoleSeeder) ; référentiels par défaut appelés par ReferentielsSitesSeeder :
     *   niveaux, zones, types de boutiques et unités de loyer M2, UNITE, REDEVANCE (NiveauxSeeder, ZonesSeeder,
     *   TypesBoutiqueSeeder, UnitesSeeder ; chacun lançable seul, y compris en production, sans rien écraser) ;
     *   moyens de paiement par défaut (MoyensPaiementSeeder : Faso Arzeka, Orange Money, Moov Africa Money,
     *   Telecel Money, virement et versement bancaires, carte bancaire, espèces ; Coris Money et Wave créés inactifs).
     * - Comptes de démonstration (ComptesDemoSeeder, mot de passe = adresse e-mail, par exemple sites@raaga.test) :
     *   jamais en production. Avec APP_ENV=production, cette étape est sautée, et ComptesDemoSeeder lancé seul refuse
     *   de s'exécuter ; « php artisan raaga:comptes-demo --desactiver » désactive ceux qui existeraient déjà.
     * - Tranche de démonstration (TrancheDemoSeeder) : 10 000 FCFA l'action, minimum 1, en brouillon ; jamais en production.
     */
    public function run(): void
    {
        $this->call(RoleSeeder::class);
        // Référentiels (idempotents, aussi lancés seuls par deploy.sh) : paramètres, niveaux, zones, types, unités, moyens de paiement.
        $this->call(ReferentielsSeeder::class);

        // Comptes de démonstration : jamais en production.
        if (! app()->environment('production')) {
            $this->call(ComptesDemoSeeder::class);
            // Tranche de démonstration à 10 000 FCFA, minimum 1 action, en brouillon.
            $this->call(TrancheDemoSeeder::class);
        }
    }
}
