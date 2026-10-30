<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Réimpression à l'identique : chaque carte garde, à son émission, la référence des fichiers (armoiries, logo,
 * signature, cachet) et les textes de l'autorité et le numéro vert utilisés. Une réimpression les réutilise.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cartes', function (Blueprint $table) {
            $table->json('reglages_rendu')->nullable()->after('contenu');
        });
    }

    public function down(): void
    {
        Schema::table('cartes', function (Blueprint $table) {
            $table->dropColumn('reglages_rendu');
        });
    }
};
