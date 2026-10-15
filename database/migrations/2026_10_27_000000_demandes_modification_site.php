<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Demande d'autorisation de modifier un site validé : le GESTIONNAIRE DES SITES ET BOUTIQUES la dépose (motif), le
     * VALIDATEUR l'accepte (le site repasse en brouillon) ou la refuse (motif). File « À valider » du VALIDATEUR.
     */
    public function up(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->foreignId('revision_demandee_par')->nullable()->after('motif_rejet')->constrained('users')->restrictOnDelete();
            $table->timestamp('revision_demandee_le')->nullable()->after('revision_demandee_par');
            $table->text('revision_demande_motif')->nullable()->after('revision_demandee_le');
        });
    }

    public function down(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->dropConstrainedForeignId('revision_demandee_par');
            $table->dropColumn(['revision_demandee_le', 'revision_demande_motif']);
        });
    }
};
