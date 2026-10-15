<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Unité des lignes de grille tarifaire : clé étrangère vers le référentiel « Unités » (unites.code), comme les niveaux
     * et les zones. Les valeurs existantes passent en majuscules (« m2 » → « M2 ») ; une unité utilisée par une ligne
     * de grille ne peut plus être supprimée, même directement en base.
     */
    public function up(): void
    {
        DB::table('grille_tarifaire_lignes')->update(['unite_loyer' => DB::raw('UPPER(unite_loyer)')]);
        Schema::table('grille_tarifaire_lignes', function (Blueprint $table) {
            $table->string('unite_loyer', 10)->default('M2')->change();
            $table->foreign('unite_loyer')->references('code')->on('unites')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('grille_tarifaire_lignes', function (Blueprint $table) {
            $table->dropForeign(['unite_loyer']);
            $table->string('unite_loyer', 10)->default('m2')->change();
        });
        DB::table('grille_tarifaire_lignes')->update(['unite_loyer' => DB::raw('LOWER(unite_loyer)')]);
    }
};
