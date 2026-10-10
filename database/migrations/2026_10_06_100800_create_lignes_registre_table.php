<?php

use App\Support\AppendOnlyTable;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Registre des actions B — ajout seul.
     */
    public function up(): void
    {
        Schema::create('lignes_registre', function (Blueprint $table) {
            $table->id();
            $table->string('numero_certificat', 30)->unique();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('tranche_id')->constrained()->restrictOnDelete();
            $table->foreignId('souscription_id')->nullable()->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('nb_actions');
            $table->unsignedBigInteger('numero_action_debut');
            $table->unsignedBigInteger('numero_action_fin');
            $table->timestamp('date_inscription');
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['tranche_id', 'numero_action_debut']);
        });

        AppendOnlyTable::protect('lignes_registre');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        AppendOnlyTable::unprotect('lignes_registre');
        Schema::dropIfExists('lignes_registre');
    }
};
