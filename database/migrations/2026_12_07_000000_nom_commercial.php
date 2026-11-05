<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Nom commercial du commerçant (réunion du 05/11/2026, lot 3) : facultatif, saisi dans « Mon profil » une fois l'IFU
 * validé ; affiché sur la fiche de la boutique et dans le dossier ; jamais sur la carte sans confirmation du comité.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', fn (Blueprint $table) => $table->string('nom_commercial', 120)->nullable()->after('raison_sociale'));
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('nom_commercial'));
    }
};
