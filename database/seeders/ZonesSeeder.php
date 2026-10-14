<?php

namespace Database\Seeders;

use App\Enums\StatutTypeBoutique;
use App\Models\ZoneSite;
use Illuminate\Database\Seeder;

/**
 * Zones par défaut (fichier de référence « Raaga_import_sites.xlsx », onglet « Listes »). Indépendant des données de
 * démonstration : s'exécute aussi en production (php artisan db:seed --class=ZonesSeeder --force) et depuis
 * DatabaseSeeder. Idempotent : il crée seulement les codes absents et ne modifie jamais une zone existante.
 */
class ZonesSeeder extends Seeder
{
    /** [code, libellé]. */
    public const ZONES = [
        ['A', 'Bloc A'], ['B', 'Bloc B'], ['MAG', 'Bloc ou bâtiment magasins'], ['ETAL', 'Zone des étals'],
        ['BP', 'Pôle boucherie-poissonnerie'], ['CF', 'Chambres froides'], ['REST', 'Restaurant'],
    ];

    public function run(): void
    {
        foreach (self::ZONES as $i => [$code, $libelle]) {
            $zone = ZoneSite::query()->firstOrCreate(['code' => $code], [
                'libelle' => $libelle, 'ordre' => $i + 1, 'actif' => true, 'par_defaut' => true, 'statut' => StatutTypeBoutique::Valide,
            ]);
            if ($zone->wasRecentlyCreated) {
                $zone->creerVersion(null, __('admin.types.motifs.defaut'));
            }
        }
    }
}
