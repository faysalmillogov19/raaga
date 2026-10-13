<?php

namespace Database\Seeders;

use App\Models\TypeBoutique;
use App\Models\ZoneSite;
use Illuminate\Database\Seeder;

/**
 * Zones et types de locaux du fichier de référence « Raaga_import_sites.xlsx » (onglet « Listes »).
 * Sans effet de bord : un type ou une zone déjà présent garde son libellé ; seuls le code manquant et
 * l'attribution par tirage sont complétés. La chambre froide mutualisée (CF) ne se tire pas au sort.
 */
class ReferentielsSitesSeeder extends Seeder
{
    /** [code, libellé]. */
    public const ZONES = [
        ['A', 'Bloc A'], ['B', 'Bloc B'], ['MAG', 'Bloc ou bâtiment magasins'], ['ETAL', 'Zone des étals'],
        ['BP', 'Pôle boucherie-poissonnerie'], ['CF', 'Chambres froides'], ['REST', 'Restaurant'],
    ];

    /** [code, libellé, attribuable par tirage]. */
    public const TYPES = [
        ['BOUT', 'Boutique', true], ['MAG', 'Magasin', true], ['ETAL', 'Étal', true],
        ['POSTE', 'Poste spécialisé (poisson, viande)', true], ['PV', 'Pôle : surface de vente', true],
        ['PP', 'Pôle : préparation et découpe privative', true], ['PF', 'Pôle : froid et stockage privatif', true],
        ['REST', 'Restaurant', true], ['CF', 'Chambre froide mutualisée', false],
    ];

    public function run(): void
    {
        foreach (self::ZONES as [$code, $libelle]) {
            ZoneSite::query()->firstOrCreate(['code' => $code], ['libelle' => $libelle]);
        }

        foreach (self::TYPES as [$code, $libelle, $attribuable]) {
            $type = TypeBoutique::query()->where('code', $code)->first()
                ?? TypeBoutique::query()->whereNull('code')->where('libelle', $libelle)->first()
                ?? new TypeBoutique(['libelle' => $libelle, 'ordre' => (int) TypeBoutique::query()->max('ordre') + 1]);
            $type->forceFill(['code' => $code, 'attribuable' => $attribuable])->save();
        }
    }
}
