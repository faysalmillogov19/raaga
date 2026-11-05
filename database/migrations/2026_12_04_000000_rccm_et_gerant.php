<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Réunion du 05/11/2026 : RCCM de retour (numéro dans la colonne rccm existante, date d'immatriculation, greffe ;
 * justificatif dans les pièces du dossier), obligatoire pour une personne morale, facultatif pour une personne
 * physique, jamais sur la carte. Gérant d'une personne morale : le titulaire du compte, dont on ajoute la fonction.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->date('rccm_immatricule_le')->nullable()->after('rccm');
            $table->string('rccm_greffe', 120)->nullable()->after('rccm_immatricule_le');
            $table->string('gerant_fonction', 120)->nullable()->after('forme_morale');
        });
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn(['rccm_immatricule_le', 'rccm_greffe', 'gerant_fonction']));
    }
};
