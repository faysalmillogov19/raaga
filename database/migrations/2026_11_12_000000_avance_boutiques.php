<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Paiement de l'avance d'une boutique réservée par le circuit de la caution, et traitement au cas par cas des
 * retards : nature de chaque paiement d'une réservation (caution ou avance), avance confirmée, dossier « avance en
 * retard », décisions proposées par le RÉGULATEUR (prolonger, annuler avec retenue, attendre) et confirmées ou
 * rejetées par le VALIDATEUR.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('paiements', fn (Blueprint $table) => $table->string('nature', 10)->nullable());
        DB::table('paiements')->whereNotNull('reservation_boutique_id')->update(['nature' => 'CAUTION']);

        Schema::table('reservations_boutiques', function (Blueprint $table) {
            $table->timestamp('avance_payee_le')->nullable();
            // Date limite dépassée sans avance confirmée : dossier « avance en retard » (aucune action automatique).
            $table->timestamp('avance_retard_le')->nullable()->index();
        });
        DB::table('reservations_boutiques')->where('statut', 'ATTRIBUEE')->whereNull('avance_payee_le')
            ->update(['avance_payee_le' => DB::raw('COALESCE(attribuee_le, updated_at)')]);

        Schema::create('decisions_avances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('reservation_boutique_id')->constrained('reservations_boutiques')->restrictOnDelete();
            $table->string('decision', 12);
            $table->text('motif');
            $table->timestamp('nouvelle_date')->nullable();
            $table->unsignedTinyInteger('retenue_pct')->nullable();
            $table->string('statut', 12)->index();
            $table->foreignId('propose_par')->constrained('users')->restrictOnDelete();
            $table->timestamp('propose_le');
            $table->foreignId('decide_par')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('decide_le')->nullable();
            $table->text('motif_decision')->nullable();
            $table->foreignId('remboursement_id')->nullable()->constrained('remboursements')->restrictOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('decisions_avances');
        Schema::table('reservations_boutiques', fn (Blueprint $table) => $table->dropColumn(['avance_payee_le', 'avance_retard_le']));
        Schema::table('paiements', fn (Blueprint $table) => $table->dropColumn('nature'));
    }
};
