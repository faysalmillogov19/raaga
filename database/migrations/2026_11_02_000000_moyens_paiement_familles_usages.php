<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Moyens de paiement : famille (plateforme publique, banque, mobile money, guichet), instructions affichées au citoyen,
 * usages (actions, caution : autorisé, montant minimum et maximum par opération, vide = sans limite). Nouveaux moyens
 * de départ : VERSEMENT (actif), CORIS_MONEY et WAVE (créés inactifs). Confirmés d'office, version 1 au nom du système,
 * sans décision de validation. Aucune clé ni aucun secret d'opérateur ici : ils restent dans le .env.
 */
return new class extends Migration
{
    private const FAMILLES = ['MOBILE_MONEY' => 'MOBILE_MONEY', 'PASSERELLE' => 'PLATEFORME_PUBLIQUE', 'VIREMENT' => 'BANQUE', 'ESPECES' => 'GUICHET'];

    private const NOUVEAUX = [
        // code, libellé, famille, ordre, actif
        ['VERSEMENT', 'Versement bancaire', 'BANQUE', 6, true],
        ['CORIS_MONEY', 'Coris Money', 'MOBILE_MONEY', 8, false],
        ['WAVE', 'Wave', 'MOBILE_MONEY', 9, false],
    ];

    public function up(): void
    {
        Schema::table('moyens_paiement', function (Blueprint $table) {
            $table->string('famille', 20)->nullable()->after('type');
            $table->text('instructions')->nullable()->after('description');
            $table->json('usages')->nullable()->after('instructions');
        });
        foreach (self::FAMILLES as $type => $famille) {
            DB::table('moyens_paiement')->where('type', $type)->update(['famille' => $famille]);
        }
        $usages = json_encode(self::usages());
        DB::table('moyens_paiement')->update(['usages' => $usages]);
        foreach (['FASO_ARZEKA' => 1, 'ORANGE_MONEY' => 2, 'MOOV_MONEY' => 3, 'TELECEL_MONEY' => 4, 'VIREMENT' => 5, 'ESPECES' => 7] as $code => $ordre) {
            DB::table('moyens_paiement')->where('code', $code)->update(['ordre' => $ordre]);
        }
        Schema::table('moyens_paiement', fn (Blueprint $t) => $t->dropColumn('type'));

        $maintenant = now();
        foreach (self::NOUVEAUX as [$code, $libelle, $famille, $ordre, $actif]) {
            if (DB::table('moyens_paiement')->where('code', $code)->exists()) {
                continue;
            }
            $id = DB::table('moyens_paiement')->insertGetId([
                'code' => $code, 'libelle' => $libelle, 'famille' => $famille, 'usages' => $usages, 'ordre' => $ordre, 'actif' => $actif,
                'par_defaut' => true, 'statut' => 'VALIDE', 'valide_le' => $maintenant, 'version' => 1, 'created_at' => $maintenant, 'updated_at' => $maintenant,
            ]);
            DB::table('referentiel_versions')->insert([
                'objet_type' => 'moyen_paiement', 'objet_id' => $id, 'numero' => 1, 'auteur_id' => null, 'motif' => 'Valeur par défaut, confirmée d’office',
                'created_at' => $maintenant, 'parametres' => json_encode(['code' => $code, 'libelle' => $libelle, 'famille' => $famille, 'instructions' => null,
                    'usages' => self::usages(), 'ordre' => $ordre, 'actif' => $actif, 'statut' => 'VALIDE']),
            ]);
        }
    }

    /** Les deux usages autorisés, sans plafond (le RÉGULATEUR les saisit). */
    private static function usages(): array
    {
        return ['ACTIONS' => ['autorise' => true, 'min' => null, 'max' => null], 'CAUTION' => ['autorise' => true, 'min' => null, 'max' => null]];
    }

    public function down(): void
    {
        Schema::table('moyens_paiement', function (Blueprint $table) {
            $table->string('type', 20)->nullable();
            $table->dropColumn(['famille', 'instructions', 'usages']);
        });
    }
};
