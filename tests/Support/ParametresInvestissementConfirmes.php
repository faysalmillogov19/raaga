<?php

namespace Tests\Support;

use App\Enums\DecisionParametrage;
use App\Enums\StatutGrilleTarifaire;
use App\Models\ParametrageDecision;
use App\Models\ParametresInvestissement;
use App\Models\User;

/**
 * Paramètres d'investissement RAAGA Invest déjà confirmés (fiche, version figée, décision du VALIDATEUR), sans
 * passer par les écrans : pour les tests qui n'éprouvent pas le workflow lui-même.
 */
trait ParametresInvestissementConfirmes
{
    protected function confirmerParametresInvestissement(int $valeur, int $objectif = 1_000_000_000, ?int $plafond = null): ParametresInvestissement
    {
        $auteur = User::factory()->create();
        $fiche = ParametresInvestissement::create(['objectif_collecte' => $objectif, 'valeur_action' => $valeur, 'plafond_global' => $plafond,
            'statut' => StatutGrilleTarifaire::Confirme]);
        $version = $fiche->creerVersion($auteur);
        ParametrageDecision::create(['objet_type' => 'investissement', 'objet_id' => $fiche->id, 'version' => $version->numero,
            'decision' => DecisionParametrage::Confirme, 'decideur_id' => User::factory()->create()->id, 'created_at' => now()]);

        return $fiche;
    }
}
