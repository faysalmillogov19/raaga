<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Délai commun de correction d'une identité invalide (boutiques et actions), 48 heures par défaut, parmi les « Délais
 * de paiement » (RÉGULATEUR saisit, VALIDATEUR confirme). Il remplace le délai par campagne, retiré. Valeurs initiales
 * des délais posées par un seeder, confirmées d'office (version et décision sans auteur). Investisseur : date limite de
 * correction et gel des actions (attestation et carte d'actionnaire bloquées, dividendes toujours versés).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('parametres_paiements', function (Blueprint $table) {
            $table->unsignedInteger('delai_kyc_valeur')->nullable();
            $table->foreignId('delai_kyc_duree_id')->nullable()->constrained('durees', indexName: 'pp_kyc_duree_fk')->restrictOnDelete();
            $table->unsignedInteger('delai_kyc_secondes')->nullable();
        });

        // Valeurs initiales confirmées d'office : ni auteur ni décideur (installation).
        Schema::table('parametres_paiements_versions', fn (Blueprint $table) => $table->foreignId('auteur_id')->nullable()->change());
        Schema::table('parametrage_decisions', fn (Blueprint $table) => $table->foreignId('decideur_id')->nullable()->change());

        Schema::table('campagnes_boutiques', function (Blueprint $table) {
            $table->dropForeign('cb_kyc_duree_fk');
            $table->dropColumn(['delai_kyc_valeur', 'delai_kyc_duree_id', 'delai_kyc_secondes']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('kyc_actions_a_corriger_avant')->nullable();
            $table->timestamp('actions_gelees_le')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn(['kyc_actions_a_corriger_avant', 'actions_gelees_le']));
        Schema::table('campagnes_boutiques', function (Blueprint $table) {
            $table->unsignedInteger('delai_kyc_valeur')->nullable();
            $table->foreignId('delai_kyc_duree_id')->nullable()->constrained('durees', indexName: 'cb_kyc_duree_fk')->restrictOnDelete();
            $table->unsignedInteger('delai_kyc_secondes')->nullable();
        });
        Schema::table('parametres_paiements', function (Blueprint $table) {
            $table->dropForeign('pp_kyc_duree_fk');
            $table->dropColumn(['delai_kyc_valeur', 'delai_kyc_duree_id', 'delai_kyc_secondes']);
        });
    }
};
