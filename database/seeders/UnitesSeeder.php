<?php

namespace Database\Seeders;

use App\Enums\StatutTypeBoutique;
use App\Models\Unite;
use Illuminate\Database\Seeder;

/** Unités de loyer par défaut (validées, « par défaut ») : créées si elles manquent, jamais modifiées si elles existent. */
class UnitesSeeder extends Seeder
{
    public const UNITES = [
        'M2' => 'Au mètre carré par mois',
        'UNITE' => 'À l’unité (étal, poste) par mois',
        'REDEVANCE' => 'Redevance d’usage',
    ];

    public function run(): void
    {
        foreach (self::UNITES as $code => $libelle) {
            Unite::query()->firstOrCreate(['code' => $code], ['libelle' => $libelle, 'actif' => true, 'par_defaut' => true, 'statut' => StatutTypeBoutique::Valide]);
        }
    }
}
