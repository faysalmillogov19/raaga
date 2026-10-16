<?php

namespace Database\Seeders;

use App\Enums\Role;
use App\Enums\StatutTranche;
use App\Http\Controllers\Admin\TranchesController;
use App\Models\Tranche;
use App\Models\User;
use App\Support\VerrouEnvironnement;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

/**
 * Tranche de démonstration (hors production) : action à 10 000 FCFA, minimum 1 action, en brouillon. Le RÉGULATEUR la
 * soumet, le VALIDATEUR la confirme (prix et minimum alors figés). Créée si elle manque, jamais modifiée ensuite.
 */
class TrancheDemoSeeder extends Seeder
{
    use WithoutModelEvents;

    public const NOM = 'Tranche de démonstration';

    public function run(): void
    {
        VerrouEnvironnement::exigerHorsProduction('tranche de démonstration');

        $auteur = User::query()->where('email', 'regulation'.ComptesDemoSeeder::DOMAINE)->first()
            ?? User::query()->whereHas('role', fn ($q) => $q->where('code', Role::Regulateur->value))->first();
        if ($auteur === null || Tranche::query()->where('nom', self::NOM)->exists()) {
            return;
        }

        Tranche::create([
            ...TranchesController::valeursParDefaut(),
            'nom' => self::NOM,
            'valeur_unitaire' => 10_000,
            'min_actions_par_souscription' => 1,
            'statut' => StatutTranche::Brouillon,
            'version' => 0,
            'cree_par' => $auteur->id,
        ]);
    }
}
