<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Clôture de la caisse d'un agent pour une journée : verrouille les encaissements du jour.
     */
    public function up(): void
    {
        Schema::create('clotures_caisse', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agent_id')->constrained('users')->restrictOnDelete();
            $table->date('date_caisse');
            $table->unsignedInteger('nb_encaissements');
            $table->unsignedBigInteger('total_montant');
            $table->timestamp('cloturee_le');
            $table->timestamps();

            $table->unique(['agent_id', 'date_caisse']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('clotures_caisse');
    }
};
