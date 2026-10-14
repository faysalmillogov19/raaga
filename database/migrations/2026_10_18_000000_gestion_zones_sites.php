<?php

use Database\Seeders\ReferentielsSitesSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Zones gérées par le GESTIONNAIRE DES SITES ET BOUTIQUES : actif, « par défaut », validation d'une zone ajoutée
 * par le VALIDATEUR. Les zones déjà présentes restent utilisables ; celles du fichier de référence sont « par défaut ».
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('zones_sites', function (Blueprint $table) {
            $table->boolean('actif')->default(true)->after('libelle');
            $table->boolean('par_defaut')->default(false)->after('actif');
            $table->string('statut', 12)->default('VALIDE')->index()->after('par_defaut');
            $table->foreignId('demande_par')->nullable()->after('statut')->constrained('users')->restrictOnDelete();
            $table->foreignId('valide_par')->nullable()->after('demande_par')->constrained('users')->restrictOnDelete();
            $table->timestamp('valide_le')->nullable()->after('valide_par');
            $table->text('motif_rejet')->nullable()->after('valide_le');
        });
        DB::table('zones_sites')->whereIn('code', array_column(ReferentielsSitesSeeder::ZONES, 0))->update(['par_defaut' => true]);
    }

    public function down(): void
    {
        Schema::table('zones_sites', function (Blueprint $table) {
            $table->dropConstrainedForeignId('demande_par');
            $table->dropConstrainedForeignId('valide_par');
            $table->dropColumn(['actif', 'par_defaut', 'statut', 'valide_le', 'motif_rejet']);
        });
    }
};
