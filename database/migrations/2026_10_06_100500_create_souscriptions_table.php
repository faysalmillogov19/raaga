<?php

use App\Enums\StatutSouscription;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('souscriptions', function (Blueprint $table) {
            $table->id();
            // Numéro unique imprimé sur le reçu.
            $table->string('reference', 30)->unique();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('tranche_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('nb_actions_demandees');
            // Prix figé au moment de la souscription.
            $table->unsignedBigInteger('valeur_unitaire');
            $table->unsignedBigInteger('montant');
            $table->unsignedBigInteger('frais_souscription')->default(0);
            $table->string('canal', 20);
            $table->foreignId('agent_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('statut', 20)->default(StatutSouscription::Reservee->value)->index();

            // Revue conformité (au-delà de seuil_revue_conformite)
            $table->string('revue_statut', 20)->nullable()->index();
            $table->foreignId('revue_par')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('revue_le')->nullable();
            $table->text('revue_motif')->nullable();

            // Résultat de l'allocation
            $table->unsignedBigInteger('nb_actions_allouees')->nullable();
            $table->unsignedBigInteger('montant_a_rembourser')->nullable();

            $table->dateTime('expire_le')->index();
            $table->timestamp('payee_le')->nullable();
            $table->timestamp('allouee_le')->nullable();
            $table->timestamps();

            $table->index(['tranche_id', 'statut']);
            $table->index(['user_id', 'tranche_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('souscriptions');
    }
};
