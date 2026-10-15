<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Comptes de destination séparés : chaque paiement enregistre le compte qui l'a reçu, investissement (souscription
 * d'actions) ou location (caution d'une boutique) ; une contrainte en base refuse tout mélange. Moyen CARTE_BANCAIRE
 * (famille carte bancaire : page de paiement sécurisée de l'opérateur, aucun numéro de carte chez Raaga), confirmé
 * d'office, version 1 au nom du système.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('paiements', fn (Blueprint $t) => $t->string('compte_destination', 20)->nullable()->after('canal'));
        DB::table('paiements')->whereNotNull('souscription_id')->update(['compte_destination' => 'INVESTISSEMENT']);
        DB::table('paiements')->whereNull('souscription_id')->whereNotNull('candidature_boutique_id')->update(['compte_destination' => 'LOCATION']);
        Schema::table('paiements', fn (Blueprint $t) => $t->string('compte_destination', 20)->nullable(false)->change());
        DB::statement("ALTER TABLE paiements ADD CONSTRAINT paiements_compte_destination_coherent CHECK (
            (souscription_id IS NOT NULL AND candidature_boutique_id IS NULL AND compte_destination = 'INVESTISSEMENT')
            OR (candidature_boutique_id IS NOT NULL AND souscription_id IS NULL AND compte_destination = 'LOCATION'))");

        foreach (['FASO_ARZEKA' => 1, 'ORANGE_MONEY' => 2, 'MOOV_MONEY' => 3, 'TELECEL_MONEY' => 4, 'VIREMENT' => 5, 'VERSEMENT' => 6, 'ESPECES' => 8, 'CORIS_MONEY' => 9, 'WAVE' => 10] as $code => $ordre) {
            DB::table('moyens_paiement')->where('code', $code)->update(['ordre' => $ordre]);
        }
        if (DB::table('moyens_paiement')->where('code', 'CARTE_BANCAIRE')->doesntExist()) {
            $maintenant = now();
            $usages = ['ACTIONS' => ['autorise' => true, 'min' => null, 'max' => null], 'CAUTION' => ['autorise' => true, 'min' => null, 'max' => null]];
            $id = DB::table('moyens_paiement')->insertGetId([
                'code' => 'CARTE_BANCAIRE', 'libelle' => 'Carte bancaire', 'famille' => 'CARTE_BANCAIRE', 'usages' => json_encode($usages), 'ordre' => 7,
                'actif' => true, 'par_defaut' => true, 'statut' => 'VALIDE', 'valide_le' => $maintenant, 'version' => 1, 'created_at' => $maintenant, 'updated_at' => $maintenant,
            ]);
            DB::table('referentiel_versions')->insert([
                'objet_type' => 'moyen_paiement', 'objet_id' => $id, 'numero' => 1, 'auteur_id' => null, 'motif' => 'Valeur par défaut, confirmée d’office',
                'created_at' => $maintenant, 'parametres' => json_encode(['code' => 'CARTE_BANCAIRE', 'libelle' => 'Carte bancaire', 'famille' => 'CARTE_BANCAIRE',
                    'instructions' => null, 'usages' => $usages, 'ordre' => 7, 'actif' => true, 'statut' => 'VALIDE']),
            ]);
        }
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE paiements DROP CONSTRAINT paiements_compte_destination_coherent');
        Schema::table('paiements', fn (Blueprint $t) => $t->dropColumn('compte_destination'));
    }
};
