<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Campagnes construites sur le référentiel : chaque « lot » d'une campagne = un site × un type de boutique,
 * avec sa caution ; nombre de boutiques compté dans le référentiel. Plafond de boutiques par personne.
 * La candidature retenue reçoit une boutique précise (second tirage).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('categories_boutiques', function (Blueprint $table) {
            $table->foreignId('site_id')->nullable()->after('campagne_boutiques_id')->constrained()->restrictOnDelete();
            $table->foreignId('type_boutique_id')->nullable()->after('site_id')->constrained('types_boutiques')->restrictOnDelete();
        });

        Schema::table('campagnes_boutiques', function (Blueprint $table) {
            $table->unsignedInteger('max_boutiques_par_personne')->nullable()->after('canaux_actifs');
        });

        Schema::table('candidatures_boutiques', function (Blueprint $table) {
            $table->foreignId('boutique_id')->nullable()->after('categorie_boutique_id')->constrained()->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('candidatures_boutiques', fn (Blueprint $table) => $table->dropConstrainedForeignId('boutique_id'));
        Schema::table('campagnes_boutiques', fn (Blueprint $table) => $table->dropColumn('max_boutiques_par_personne'));
        Schema::table('categories_boutiques', function (Blueprint $table) {
            $table->dropConstrainedForeignId('type_boutique_id');
            $table->dropConstrainedForeignId('site_id');
        });
    }
};
