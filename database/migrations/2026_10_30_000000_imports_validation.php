<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Import confirmé (sites et capacités, ou boutiques) et sa validation : statut choisi à l'import (brouillon ou soumis
 * au VALIDATEUR), contenu, résumé par site, niveau et type, avertissements de l'aperçu, décision sur l'import entier.
 * Sites concernés : soumis ou restés en brouillon (raison), puis résultat de la décision (confirmé, rejeté, déjà traité).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('imports_validation', function (Blueprint $table) {
            $table->id();
            $table->string('entite', 20); // sites, boutiques
            $table->string('fichier');
            $table->string('chemin')->nullable();
            $table->string('empreinte', 64)->nullable();
            $table->foreignId('auteur_id')->constrained('users')->restrictOnDelete();
            $table->string('statut_apres', 20); // BROUILLON, SOUMETTRE
            $table->json('contenu');
            $table->json('resume');
            $table->json('avertissements');
            $table->string('decision', 20)->nullable(); // VALIDE, REJETE
            $table->text('motif_rejet')->nullable();
            $table->foreignId('decide_par')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('decide_le')->nullable();
            $table->timestamps();
        });

        Schema::create('import_validation_sites', function (Blueprint $table) {
            $table->id();
            $table->foreignId('import_id')->constrained('imports_validation')->cascadeOnDelete();
            $table->foreignId('site_id')->constrained('sites')->restrictOnDelete();
            $table->timestamp('soumis_le')->nullable(); // date de la soumission faite par l'import (null : resté en brouillon)
            $table->text('raison_brouillon')->nullable();
            $table->string('resultat', 20)->nullable(); // CONFIRME, REJETE, DEJA_TRAITE
            $table->unique(['import_id', 'site_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('import_validation_sites');
        Schema::dropIfExists('imports_validation');
    }
};
