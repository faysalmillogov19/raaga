<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Chaque paramétrage de distribution précise la classe d'actions (A ou B), avec ses propres
     * montants : un paramétrage par exercice et par classe. Les paramétrages existants portent sur les actions B.
     */
    public function up(): void
    {
        Schema::table('distributions_dividendes', function (Blueprint $table) {
            $table->string('classe_actions', 1)->default('B')->after('exercice');
            $table->dropUnique(['exercice']);
            $table->unique(['exercice', 'classe_actions']);
        });
    }

    public function down(): void
    {
        Schema::table('distributions_dividendes', function (Blueprint $table) {
            $table->dropUnique(['exercice', 'classe_actions']);
            $table->unique('exercice');
            $table->dropColumn('classe_actions');
        });
    }
};
