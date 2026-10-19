<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Délais des listes d'attente, saisis par le RÉGULATEUR dans les paramètres de la campagne (valeur, durée, secondes) :
 *  - « kyc » : délai laissé à un attributaire dont l'identité est rejetée ou à mettre à jour pour la corriger ;
 *  - « relance » : délai laissé à la liste d'attente générale pour manifester à nouveau son intérêt.
 * Sur la réservation : date limite de correction de l'identité, fixée quand le problème est constaté.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('campagnes_boutiques', function (Blueprint $table) {
            foreach (['kyc', 'relance'] as $delai) {
                $table->unsignedInteger('delai_'.$delai.'_valeur')->nullable();
                $table->foreignId('delai_'.$delai.'_duree_id')->nullable()->constrained('durees', indexName: 'cb_'.$delai.'_duree_fk')->restrictOnDelete();
                $table->unsignedInteger('delai_'.$delai.'_secondes')->nullable();
            }
        });

        Schema::table('reservations_boutiques', function (Blueprint $table) {
            $table->timestamp('kyc_a_corriger_avant')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('reservations_boutiques', function (Blueprint $table) {
            $table->dropColumn('kyc_a_corriger_avant');
        });
        Schema::table('campagnes_boutiques', function (Blueprint $table) {
            foreach (['kyc', 'relance'] as $delai) {
                $table->dropForeign('cb_'.$delai.'_duree_fk');
                $table->dropColumn(['delai_'.$delai.'_valeur', 'delai_'.$delai.'_duree_id', 'delai_'.$delai.'_secondes']);
            }
        });
    }
};
