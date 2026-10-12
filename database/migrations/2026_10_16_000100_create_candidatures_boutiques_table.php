<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Candidatures des citoyens aux boutiques : catégorie choisie, caution figée au moment de la candidature,
 * statut (réservée, payée, retenue, non retenue, expirée), rang au tirage au sort.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('candidatures_boutiques', function (Blueprint $table) {
            $table->id();
            $table->string('reference', 30)->unique();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('campagne_boutiques_id')->constrained('campagnes_boutiques')->restrictOnDelete();
            $table->foreignId('categorie_boutique_id')->constrained('categories_boutiques')->restrictOnDelete();
            $table->unsignedBigInteger('caution');
            $table->string('statut', 20)->index();
            $table->dateTime('expire_le')->index();
            $table->timestamp('payee_le')->nullable();
            $table->unsignedInteger('rang_tirage')->nullable();
            $table->timestamp('tiree_le')->nullable();
            $table->timestamps();

            $table->index(['campagne_boutiques_id', 'user_id']);
        });

        // Engagements acceptés lors d'une candidature (comme pour une souscription).
        Schema::table('acceptations', function (Blueprint $table) {
            $table->foreignId('candidature_boutique_id')->nullable()->after('souscription_id')
                ->constrained('candidatures_boutiques')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('acceptations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('candidature_boutique_id');
        });
        Schema::dropIfExists('candidatures_boutiques');
    }
};
