<?php

use App\Support\AppendOnlyTable;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Historique complet des paramètres d'une tranche (snapshot à chaque modification) — ajout seul.
     */
    public function up(): void
    {
        Schema::create('tranche_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tranche_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('numero');
            $table->json('parametres');
            $table->foreignId('auteur_id')->constrained('users')->restrictOnDelete();
            $table->text('motif')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['tranche_id', 'numero']);
        });

        AppendOnlyTable::protect('tranche_versions');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        AppendOnlyTable::unprotect('tranche_versions');
        Schema::dropIfExists('tranche_versions');
    }
};
