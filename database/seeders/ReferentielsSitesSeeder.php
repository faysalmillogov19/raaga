<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * Référentiels du référencement par défaut : niveaux, zones et types de boutiques. Sans effet de bord : un élément
 * existant n'est jamais modifié. Appelé par DatabaseSeeder ; chaque seeder se lance aussi seul, y compris en production.
 */
class ReferentielsSitesSeeder extends Seeder
{
    /** Zones par défaut (conservé pour les migrations qui s'y réfèrent). */
    public const ZONES = ZonesSeeder::ZONES;

    public function run(): void
    {
        $this->call([NiveauxSeeder::class, ZonesSeeder::class, TypesBoutiqueSeeder::class, UnitesSeeder::class]);
    }
}
