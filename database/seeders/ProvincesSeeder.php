<?php

namespace Database\Seeders;

use App\Models\Province;
use App\Models\Region;
use Illuminate\Database\Seeder;

/**
 * Provinces du Burkina Faso (47, découpage en vigueur depuis le 01/08/2025), chacune rattachée à sa région (créée au
 * besoin par RegionsSeeder). Lançable seul, y compris en production. Idempotent : il crée seulement les codes absents et
 * ne modifie jamais une province existante.
 */
class ProvincesSeeder extends Seeder
{
    public function run(): void
    {
        $this->callSilent(RegionsSeeder::class);
        $regions = Region::query()->pluck('id', 'code');

        foreach (RegionsSeeder::donnees()['provinces'] as $p) {
            Province::query()->firstOrCreate(['code' => $p['code']], [
                'region_id' => $regions[$p['region']], 'nom' => $p['nom'],
            ]);
        }
    }
}
