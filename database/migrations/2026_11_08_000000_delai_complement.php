<?php

use App\Support\AppendOnlyTable;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Paramètres des paiements (une seule fiche), saisis par le RÉGULATEUR et confirmés par le VALIDATEUR : délai de
 * complément d'un paiement incomplet (48 h par défaut tant que rien n'est confirmé). Montant reçu d'un paiement
 * incomplet : remboursé si le complément n'arrive pas à l'échéance.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('parametres_paiements', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('delai_complement_heures')->nullable();
            $table->string('statut', 20)->default('BROUILLON');
            $table->unsignedInteger('version')->default(0);
            $table->foreignId('soumis_par')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('soumis_le')->nullable();
            $table->foreignId('valide_par')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('valide_le')->nullable();
            $table->text('motif_rejet')->nullable();
            $table->timestamps();
        });

        Schema::create('parametres_paiements_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('parametres_paiements_id')->constrained('parametres_paiements', indexName: 'ppv_parametres_fk')->restrictOnDelete();
            $table->unsignedInteger('numero');
            $table->json('parametres');
            $table->foreignId('auteur_id')->constrained('users')->restrictOnDelete();
            $table->string('motif')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->unique(['parametres_paiements_id', 'numero'], 'ppv_numero_unique');
        });
        AppendOnlyTable::protect('parametres_paiements_versions');

        Schema::table('paiements', function (Blueprint $table) {
            $table->unsignedBigInteger('controle_montant_recu')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('paiements', fn (Blueprint $table) => $table->dropColumn('controle_montant_recu'));
        Schema::dropIfExists('parametres_paiements_versions');
        Schema::dropIfExists('parametres_paiements');
    }
};
