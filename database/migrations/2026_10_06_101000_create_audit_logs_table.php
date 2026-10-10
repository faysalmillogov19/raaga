<?php

use App\Support\AppendOnlyTable;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Journal d'audit — ajout seul, aucune modification ni suppression possible.
     */
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            // Null = action système (tâche planifiée…).
            $table->foreignId('acteur_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('action', 80)->index();
            $table->string('entite_type', 80);
            $table->string('entite_id', 40)->nullable();
            $table->json('ancienne_valeur')->nullable();
            $table->json('nouvelle_valeur')->nullable();
            $table->string('ip', 45)->nullable();
            $table->timestamp('created_at', 6)->useCurrent()->index();

            $table->index(['entite_type', 'entite_id']);
        });

        AppendOnlyTable::protect('audit_logs');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        AppendOnlyTable::unprotect('audit_logs');
        Schema::dropIfExists('audit_logs');
    }
};
