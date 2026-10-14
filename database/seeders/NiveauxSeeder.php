<?php

namespace Database\Seeders;

use App\Enums\StatutTypeBoutique;
use App\Models\Niveau;
use Illuminate\Database\Seeder;

/**
 * Niveaux par défaut. Indépendant des données de démonstration : s'exécute aussi en production
 * (php artisan db:seed --class=NiveauxSeeder --force) et depuis DatabaseSeeder. Idempotent : il crée seulement les
 * codes absents et ne modifie jamais un niveau existant.
 */
class NiveauxSeeder extends Seeder
{
    /** [code, libellé, rang]. */
    public const NIVEAUX = [['SS', 'Sous-sol', -1], ['RC', 'Rez-de-chaussée', 0], ['R1', 'R+1', 1], ['R2', 'R+2', 2], ['R3', 'R+3', 3]];

    public function run(): void
    {
        foreach (self::NIVEAUX as [$code, $libelle, $rang]) {
            $niveau = Niveau::query()->firstOrCreate(['code' => $code], [
                'libelle' => $libelle, 'rang' => $rang, 'actif' => true, 'par_defaut' => true, 'statut' => StatutTypeBoutique::Valide,
            ]);
            if ($niveau->wasRecentlyCreated) {
                $niveau->creerVersion(null, __('admin.types.motifs.defaut'));
            }
        }
    }
}
