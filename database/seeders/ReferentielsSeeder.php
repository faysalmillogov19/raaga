<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * Seeders de référentiel lancés à chaque déploiement (deploy.sh) : paramètres de la plateforme, niveaux, zones, types
 * de boutiques, unités, durées des délais, moyens de paiement. Idempotents : ils créent seulement ce qui manque et ne modifient ni ne
 * suppriment jamais une ligne existante (les modifications faites par le GESTIONNAIRE ou le RÉGULATEUR sont gardées).
 * Ni comptes de démonstration ni contenus de recette (voir DatabaseSeeder).
 */
class ReferentielsSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([ParametresPlateformeSeeder::class, DureesSeeder::class, ReferentielsSitesSeeder::class, MoyensPaiementSeeder::class]);
    }
}
