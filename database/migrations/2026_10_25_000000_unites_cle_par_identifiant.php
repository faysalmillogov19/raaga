<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Unité des lignes de grille tarifaire : clé étrangère par identifiant (unite_id → unites.id) au lieu du code.
     * Chaque ligne reçoit l'identifiant de l'unité de même code, puis l'ancienne colonne unite_loyer est retirée.
     */
    public function up(): void
    {
        Schema::table('grille_tarifaire_lignes', function (Blueprint $table) {
            $table->foreignId('unite_id')->nullable()->after('type_boutique_id')->constrained('unites')->restrictOnDelete();
        });
        foreach (DB::table('unites')->pluck('id', 'code') as $code => $id) {
            DB::table('grille_tarifaire_lignes')->whereRaw('UPPER(unite_loyer) = ?', [$code])->update(['unite_id' => $id]);
        }
        // Valeur inconnue (aucune en pratique) : au m², comme le calcul le faisait déjà.
        DB::table('grille_tarifaire_lignes')->whereNull('unite_id')->update(['unite_id' => DB::table('unites')->where('code', 'M2')->value('id')]);
        Schema::table('grille_tarifaire_lignes', function (Blueprint $table) {
            $table->dropForeign(['unite_loyer']);
            $table->dropColumn('unite_loyer');
        });
        Schema::table('grille_tarifaire_lignes', function (Blueprint $table) {
            $table->foreignId('unite_id')->nullable(false)->change();
        });
    }

    public function down(): void
    {
        Schema::table('grille_tarifaire_lignes', function (Blueprint $table) {
            $table->string('unite_loyer', 10)->default('M2')->after('type_boutique_id');
        });
        foreach (DB::table('unites')->pluck('code', 'id') as $id => $code) {
            DB::table('grille_tarifaire_lignes')->where('unite_id', $id)->update(['unite_loyer' => $code]);
        }
        Schema::table('grille_tarifaire_lignes', function (Blueprint $table) {
            $table->dropConstrainedForeignId('unite_id');
            $table->foreign('unite_loyer')->references('code')->on('unites')->restrictOnDelete();
        });
    }
};
