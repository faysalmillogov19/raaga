<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Échec définitif de la génération des fichiers d'une carte (trois essais) : date, version concernée et cause. Le
 * titulaire en est informé, le back-office l'affiche avec un bouton de relance. Effacé à la génération réussie.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cartes', function (Blueprint $table) {
            $table->timestamp('fichiers_echec_le')->nullable()->after('reglages_rendu');
            $table->unsignedInteger('fichiers_echec_version')->nullable()->after('fichiers_echec_le');
            $table->string('fichiers_echec_cause', 500)->nullable()->after('fichiers_echec_version');
        });
    }

    public function down(): void
    {
        Schema::table('cartes', function (Blueprint $table) {
            $table->dropColumn(['fichiers_echec_le', 'fichiers_echec_version', 'fichiers_echec_cause']);
        });
    }
};
