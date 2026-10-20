<?php

namespace Database\Seeders;

use App\Enums\DecisionParametrage;
use App\Enums\StatutGrilleTarifaire;
use App\Models\Duree;
use App\Models\ParametrageDecision;
use App\Models\ParametresPaiements;
use App\Models\ParametresPaiementsVersion;
use Illuminate\Database\Seeder;

/**
 * Délais de paiement initiaux, confirmés d'office (version 1 et décision sans auteur ni décideur : installation) :
 * caution 72 heures, complément 48 heures, avance 30 jours, correction d'une identité invalide 48 heures (boutiques et
 * actions). Créés seulement si aucune fiche n'existe : des délais déjà saisis ou confirmés ne sont jamais écrasés.
 * Ils se modifient ensuite par le circuit habituel (RÉGULATEUR saisit, VALIDATEUR confirme).
 */
class ParametresPaiementsSeeder extends Seeder
{
    public function run(): void
    {
        if (ParametresPaiements::query()->exists()) {
            return;
        }

        $valeurs = [];
        foreach (ParametresPaiements::DELAIS as $delai => [$valeur, $code]) {
            $duree = Duree::parCode($code);
            $valeurs += ['delai_'.$delai.'_valeur' => $valeur, 'delai_'.$delai.'_duree_id' => $duree->id, 'delai_'.$delai.'_secondes' => $duree->enSecondes($valeur)];
        }

        $fiche = ParametresPaiements::query()->forceCreate([...$valeurs, 'statut' => StatutGrilleTarifaire::Confirme, 'version' => 1, 'valide_le' => now()]);
        ParametresPaiementsVersion::query()->forceCreate([
            'parametres_paiements_id' => $fiche->id, 'numero' => 1, 'parametres' => $fiche->snapshotParametres(),
            'auteur_id' => null, 'motif' => 'Valeurs initiales (installation), confirmées d’office', 'created_at' => now(),
        ]);
        ParametrageDecision::query()->create([
            'objet_type' => $fiche->typeParametrage(), 'objet_id' => $fiche->id, 'version' => 1, 'decision' => DecisionParametrage::Confirme,
            'motif' => 'Valeurs initiales (installation)', 'decideur_id' => null, 'created_at' => now(),
        ]);
    }
}
