<?php

namespace Database\Seeders;

use App\Enums\StatutTypeBoutique;
use App\Models\TypeBoutique;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Log;

/**
 * Types de boutiques par défaut. Indépendant des données de démonstration : s'exécute aussi en production
 * (php artisan db:seed --class=TypesBoutiqueSeeder --force) et depuis DatabaseSeeder. Idempotent : il crée seulement
 * les codes absents et ne modifie jamais un type existant, pour ne pas écraser les changements du GESTIONNAIRE.
 */
class TypesBoutiqueSeeder extends Seeder
{
    /** [code, libellé, attribuable par tirage]. L'unité du loyer est choisie dans la grille tarifaire. */
    public const TYPES = [
        ['BOUT', 'Boutique', true],
        ['MAG', 'Magasin', true],
        ['ETAL', 'Étal', true],
        ['POSTE', 'Poste spécialisé (poisson, viande)', true],
        ['PV', 'Pôle boucherie-poissonnerie : vente', true],
        ['PP', 'Pôle : préparation et découpe privative', true],
        ['PF', 'Pôle : froid et stockage privatif', true],
        ['REST', 'Restaurant', true],
        ['CF', 'Chambre froide mutualisée', false],
    ];

    public function run(): void
    {
        foreach (self::TYPES as $i => [$code, $libelle, $attribuable]) {
            if (TypeBoutique::query()->where('code', $code)->exists()) {
                continue; // Type existant : jamais modifié.
            }
            if (TypeBoutique::query()->where('libelle', $libelle)->exists()) {
                // Un autre type porte déjà ce libellé (libellé unique) : rien n'est écrasé, le cas est signalé.
                Log::warning('Type de boutique par défaut non créé : libellé déjà utilisé par un autre type.', ['code' => $code, 'libelle' => $libelle]);

                continue;
            }
            $type = TypeBoutique::query()->firstOrCreate(['code' => $code], [
                'libelle' => $libelle, 'attribuable' => $attribuable, 'actif' => true, 'par_defaut' => true,
                'statut' => StatutTypeBoutique::Valide, 'ordre' => $i + 1,
            ]);
            if ($type->wasRecentlyCreated) {
                $type->creerVersion(null, __('admin.types.motifs.defaut'));
            }
        }
    }
}
