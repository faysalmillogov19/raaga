<?php

use App\Enums\StatutPaiement;
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
        Schema::create('paiements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('souscription_id')->constrained()->restrictOnDelete();
            $table->string('canal', 20);
            $table->unsignedBigInteger('montant');
            $table->unsignedBigInteger('frais')->default(0);
            // Clé d'idempotence : une confirmation rejouée ne crée jamais de second paiement.
            $table->string('reference', 64)->unique();
            $table->string('reference_fournisseur')->nullable();
            $table->string('statut', 20)->default(StatutPaiement::EnAttente->value)->index();
            $table->timestamp('date_confirmation')->nullable();
            $table->foreignId('agent_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('cloture_caisse_id')->nullable()->constrained('clotures_caisse')->restrictOnDelete();
            $table->json('metadonnees')->nullable();
            $table->timestamps();

            $table->index(['agent_id', 'date_confirmation']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('paiements');
    }
};
