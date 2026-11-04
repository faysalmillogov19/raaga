<?php

namespace Database\Seeders;

use App\Models\Commune;
use App\Models\Province;
use Illuminate\Database\Seeder;

/**
 * Communes du Burkina Faso (351, découpage en vigueur depuis le 01/08/2025), chacune rattachée à sa province (créée au
 * besoin par ProvincesSeeder, avec sa région). Lançable seul, y compris en production. Idempotent : il crée seulement
 * les codes absents et ne modifie jamais une commune existante.
 */
class CommunesSeeder extends Seeder
{
    public function run(): void
    {
        $this->callSilent(ProvincesSeeder::class);
        $provinces = Province::query()->pluck('id', 'code');

        foreach (RegionsSeeder::donnees()['communes'] as $c) {
            Commune::query()->firstOrCreate(['code' => $c['code']], [
                'province_id' => $provinces[$c['province']], 'nom' => $c['nom'],
            ]);
        }
    }
}
