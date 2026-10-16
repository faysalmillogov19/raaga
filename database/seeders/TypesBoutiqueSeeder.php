<?php

namespace Database\Seeders;

use App\Enums\StatutTypeBoutique;
use App\Models\TypeBoutique;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Log;

/**
 * Types de boutiques par défaut. Indépendant des données de démonstration : s'exécute aussi en production
 * (php artisan db:seed --class=TypesBoutiqueSeeder --force) et depuis DatabaseSeeder. Idempotent : il crée seulement
 * les codes absents et ne modifie jamais un type existant, pour ne pas écraser les changements du GESTIONNAIRE ; seule
 * la description pour le citoyen d'un type par défaut, si elle est encore vide, reçoit sa valeur par défaut, confirmée
 * d'office (sans circuit de validation).
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

    /** Description pour le citoyen, par code (plan des boutiques et récapitulatif avant réservation). */
    public const DESCRIPTIONS = [
        'BOUT' => 'Boutique fermée avec rideau, pour le commerce de détail.',
        'MAG' => 'Grand local fermé pour le commerce de gros ou le stockage de marchandises.',
        'ETAL' => 'Emplacement de vente ouvert, sous couvert, pour les produits frais ou de détail.',
        'POSTE' => 'Poste de vente équipé pour le poisson ou la viande, raccordé à l’eau et à l’évacuation.',
        'PV' => 'Espace de vente au comptoir du pôle boucherie-poissonnerie.',
        'PP' => 'Zone de préparation et de découpe privative du pôle boucherie-poissonnerie.',
        'PF' => 'Espace de froid et de stockage privatif du pôle boucherie-poissonnerie.',
        'REST' => 'Local de restauration avec espace de cuisine.',
        'CF' => 'Chambre froide partagée entre les occupants du pôle ; jamais réservée par une personne.',
    ];

    public function run(): void
    {
        foreach (self::TYPES as $i => [$code, $libelle, $attribuable]) {
            $existant = TypeBoutique::query()->where('code', $code)->first();
            if ($existant) {
                // Type existant : jamais modifié, sauf une description pour le citoyen encore vide d'un type par défaut.
                if ($existant->par_defaut && $existant->description_citoyen === null && isset(self::DESCRIPTIONS[$code])) {
                    $existant->forceFill(['description_citoyen' => self::DESCRIPTIONS[$code]])->saveQuietly();
                }

                continue;
            }
            if (TypeBoutique::query()->where('libelle', $libelle)->exists()) {
                // Un autre type porte déjà ce libellé (libellé unique) : rien n'est écrasé, le cas est signalé.
                Log::warning('Type de boutique par défaut non créé : libellé déjà utilisé par un autre type.', ['code' => $code, 'libelle' => $libelle]);

                continue;
            }
            $type = TypeBoutique::query()->firstOrCreate(['code' => $code], [
                'libelle' => $libelle, 'description_citoyen' => self::DESCRIPTIONS[$code] ?? null, 'attribuable' => $attribuable, 'actif' => true, 'par_defaut' => true,
                'statut' => StatutTypeBoutique::Valide, 'ordre' => $i + 1,
            ]);
            if ($type->wasRecentlyCreated) {
                $type->creerVersion(null, __('admin.types.motifs.defaut'));
            }
        }
    }
}
