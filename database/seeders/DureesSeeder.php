<?php

namespace Database\Seeders;

use App\Models\Duree;
use Illuminate\Database\Seeder;

/**
 * Durées des délais (SECONDE, MINUTE, HEURE, JOUR) : confirmées d'office, sans journal de validation, non modifiables
 * à l'écran. Créées si elles manquent, jamais modifiées si elles existent (idempotent).
 */
class DureesSeeder extends Seeder
{
    public function run(): void
    {
        $ordre = 0;
        foreach (Duree::DUREES as $code => [$libelle, $pluriel, $secondes]) {
            Duree::query()->firstOrCreate(['code' => $code], ['libelle' => $libelle, 'libelle_pluriel' => $pluriel, 'secondes' => $secondes, 'ordre' => ++$ordre]);
        }
    }
}
