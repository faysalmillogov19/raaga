<?php

use App\Enums\Role;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Démarches du site public : la proposition passe du SUPER_ADMIN au RÉGULATEUR. Une proposition encore en attente
 * faite par un SUPER_ADMIN est retirée (statut « Retirée ») et le retrait est inscrit au journal d'audit (ajout seul).
 * Les décisions déjà confirmées ou rejetées ne changent pas.
 */
return new class extends Migration
{
    public function up(): void
    {
        $superAdmins = DB::table('users')->join('roles', 'roles.id', '=', 'users.role_id')->where('roles.code', Role::SuperAdmin->value)->pluck('users.id');
        $maintenant = now();

        foreach (DB::table('decisions_phase')->where('statut', 'SOUMISE')->whereIn('propose_par', $superAdmins)->pluck('id') as $id) {
            DB::table('decisions_phase')->where('id', $id)->update(['statut' => 'RETIREE', 'updated_at' => $maintenant]);
            DB::table('audit_logs')->insert([
                'acteur_id' => null, 'action' => 'phase.retiree', 'entite_type' => 'DecisionPhase', 'entite_id' => $id,
                'ancienne_valeur' => json_encode(['statut' => 'SOUMISE']),
                'nouvelle_valeur' => json_encode(['statut' => 'RETIREE', 'motif' => 'Proposition réservée au RÉGULATEUR']),
                'created_at' => $maintenant,
            ]);
        }
    }

    public function down(): void
    {
        // Ajout seul : rien n'est remis en attente.
    }
};
