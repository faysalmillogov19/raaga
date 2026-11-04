<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Commune de résidence des citoyens au Burkina Faso, demandée à l'inscription à la place de la région (03/11/2026).
 * La colonne region reste : elle reprend le nom de la région de la commune choisie, pour les listes et les filtres.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('commune_id')->nullable()->after('pays_residence')->constrained('communes')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('commune_id');
        });
    }
};
