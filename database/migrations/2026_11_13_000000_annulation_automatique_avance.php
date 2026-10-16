<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Avance non payée à temps : annulation automatique de la réservation (plus de traitement au cas par cas). La marque
 * « avance en retard » disparaît ; chaque annulation garde son motif ; le remboursement de la caution attend la
 * retenue proposée par le RÉGULATEUR et confirmée par le VALIDATEUR. Les décisions déjà enregistrées (prolonger,
 * annuler, attendre) restent dans l'historique ; celles qui attendaient encore une décision deviennent caduques.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reservations_boutiques', function (Blueprint $table) {
            $table->dropIndex(['avance_retard_le']);
            $table->dropColumn('avance_retard_le');
            $table->string('annulation_motif', 30)->nullable();
        });
        DB::table('reservations_boutiques')->where('statut', 'ANNULEE')->whereIn('id', DB::table('decisions_avances')
            ->where('decision', 'ANNULER')->where('statut', 'CONFIRMEE')->select('reservation_boutique_id'))->update(['annulation_motif' => 'AVANCE_NON_PAYEE']);

        Schema::table('remboursements', fn (Blueprint $table) => $table->unsignedTinyInteger('retenue_pct')->nullable());
        DB::table('remboursements')->whereIn('id', DB::table('decisions_avances')->whereNotNull('remboursement_id')->select('remboursement_id'))
            ->update(['retenue_pct' => DB::raw('(SELECT retenue_pct FROM decisions_avances WHERE decisions_avances.remboursement_id = remboursements.id LIMIT 1)')]);

        DB::table('decisions_avances')->where('statut', 'PROPOSEE')->update(['statut' => 'CADUQUE', 'updated_at' => now()]);
    }

    public function down(): void
    {
        Schema::table('remboursements', fn (Blueprint $table) => $table->dropColumn('retenue_pct'));
        Schema::table('reservations_boutiques', function (Blueprint $table) {
            $table->dropColumn('annulation_motif');
            $table->timestamp('avance_retard_le')->nullable()->index();
        });
    }
};
