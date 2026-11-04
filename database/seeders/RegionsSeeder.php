<?php

namespace Database\Seeders;

use App\Models\Region;
use Illuminate\Database\Seeder;

/**
 * Régions du Burkina Faso (17, découpage en vigueur depuis le 01/08/2025), lues dans
 * database/seeders/data/decoupage-administratif.json (limites administratives COD-AB, version v03 : codes et noms
 * seulement, sans les tracés). S'exécute aussi en production, depuis ReferentielsSeeder. Idempotent : il crée seulement
 * les codes absents et ne modifie jamais une région existante, sauf pour renseigner un chef-lieu encore vide.
 */
class RegionsSeeder extends Seeder
{
    public const FICHIER = __DIR__.'/data/decoupage-administratif.json';

    /** Chef-lieu de chaque région (code officiel => chef-lieu). */
    public const CHEFS_LIEUX = [
        'BF62' => 'Dédougou', 'BF57' => 'Gaoua', 'BF63' => 'Fada N’Gourma', 'BF53' => 'Bobo-Dioulasso', 'BF13' => 'Ouagadougou',
        'BF49' => 'Kaya', 'BF64' => 'Dori', 'BF48' => 'Tenkodogo', 'BF50' => 'Koudougou', 'BF51' => 'Manga', 'BF55' => 'Ziniaré',
        'BF58' => 'Bogandé', 'BF59' => 'Djibo', 'BF61' => 'Tougan', 'BF47' => 'Banfora', 'BF60' => 'Diapaga', 'BF54' => 'Ouahigouya',
    ];

    /** @return array{regions: list<array<string, ?string>>, provinces: list<array<string, ?string>>, communes: list<array<string, ?string>>} */
    public static function donnees(): array
    {
        return json_decode((string) file_get_contents(self::FICHIER), true, flags: JSON_THROW_ON_ERROR);
    }

    public function run(): void
    {
        foreach (self::donnees()['regions'] as $r) {
            $region = Region::query()->firstOrCreate(['code' => $r['code']], ['nom' => $r['nom'], 'chef_lieu' => self::CHEFS_LIEUX[$r['code']] ?? null]);
            if ($region->chef_lieu === null && isset(self::CHEFS_LIEUX[$r['code']])) {
                $region->forceFill(['chef_lieu' => self::CHEFS_LIEUX[$r['code']]])->save();
            }
        }
    }
}
