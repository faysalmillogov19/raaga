<?php

use App\Enums\StatutAvenant;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Avenants à une tranche ouverte (jalon 1 : date de clôture uniquement).
     */
    public function up(): void
    {
        Schema::create('tranche_avenants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tranche_id')->constrained()->restrictOnDelete();
            $table->string('champ', 40)->default('date_cloture');
            $table->json('ancienne_valeur');
            $table->json('nouvelle_valeur');
            $table->text('motif');
            $table->string('statut', 20)->default(StatutAvenant::EnAttente->value)->index();
            $table->foreignId('saisi_par')->constrained('users')->restrictOnDelete();
            $table->foreignId('decide_par')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('decide_le')->nullable();
            $table->text('motif_decision')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tranche_avenants');
    }
};
