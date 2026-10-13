<?php

namespace Database\Seeders;

use App\Models\ZoneSite;
use Illuminate\Database\Seeder;

/**
 * Zones des sites du fichier de référence « Raaga_import_sites.xlsx » (onglet « Listes »), puis types de boutiques
 * par défaut (TypesBoutiqueSeeder). Sans effet de bord : une zone ou un type existant n'est jamais modifié.
 */
class ReferentielsSitesSeeder extends Seeder
{
    /** [code, libellé]. */
    public const ZONES = [
        ['A', 'Bloc A'], ['B', 'Bloc B'], ['MAG', 'Bloc ou bâtiment magasins'], ['ETAL', 'Zone des étals'],
        ['BP', 'Pôle boucherie-poissonnerie'], ['CF', 'Chambres froides'], ['REST', 'Restaurant'],
    ];

    public function run(): void
    {
        foreach (self::ZONES as [$code, $libelle]) {
            ZoneSite::query()->firstOrCreate(['code' => $code], ['libelle' => $libelle]);
        }

        $this->call(TypesBoutiqueSeeder::class);
    }
}
