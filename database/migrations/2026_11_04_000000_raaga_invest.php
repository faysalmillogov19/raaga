<?php

use App\Support\AppendOnlyTable;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * RAAGA Invest :
 *  - paramètres d'investissement (objectif de collecte, valeur indicative par action, plafond global facultatif),
 *    saisis par le RÉGULATEUR et confirmés par le VALIDATEUR, versions figées en ajout seul ; aucune valeur de départ ;
 *  - manifestations d'intérêt des citoyens (simulation non engageante, aucun paiement).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('parametres_investissement', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('objectif_collecte')->nullable();
            $table->unsignedBigInteger('valeur_action')->nullable();
            $table->unsignedBigInteger('plafond_global')->nullable();
            $table->string('statut', 20)->default('BROUILLON');
            $table->unsignedInteger('version')->default(0);
            $table->foreignId('soumis_par')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('soumis_le')->nullable();
            $table->foreignId('valide_par')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('valide_le')->nullable();
            $table->text('motif_rejet')->nullable();
            $table->timestamps();
        });

        Schema::create('parametres_investissement_versions', function (Blueprint $table) {
            $table->id();
            // Noms courts : PostgreSQL tronque les identifiants à 63 caractères.
            $table->foreignId('parametres_investissement_id')->constrained('parametres_investissement', indexName: 'piv_parametres_fk')->restrictOnDelete();
            $table->unsignedInteger('numero');
            $table->json('parametres');
            $table->foreignId('auteur_id')->constrained('users')->restrictOnDelete();
            $table->string('motif')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->unique(['parametres_investissement_id', 'numero'], 'piv_numero_unique');
        });
        AppendOnlyTable::protect('parametres_investissement_versions');

        Schema::create('manifestations_interet', function (Blueprint $table) {
            $table->id();
            $table->string('reference', 20)->unique();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('profil', 20);
            $table->string('pays_residence', 80);
            $table->unsignedInteger('nombre_actions');
            // Valeur indicative et montant au moment de la manifestation (simulation non engageante).
            $table->unsignedBigInteger('valeur_action');
            $table->unsignedBigInteger('montant');
            $table->timestamp('simulation_comprise_le');
            $table->timestamps();
            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('manifestations_interet');
        Schema::dropIfExists('parametres_investissement_versions');
        Schema::dropIfExists('parametres_investissement');
    }
};
