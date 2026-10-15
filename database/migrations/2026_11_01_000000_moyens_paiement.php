<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Référentiel des moyens de paiement (souscriptions, cautions, dividendes) : RÉGULATEUR saisit, VALIDATEUR confirme ;
 * code unique, jamais modifié, repris tel quel dans les paiements, les tranches, les campagnes et les distributions
 * (les codes déjà enregistrés ORANGE_MONEY, MOOV_MONEY, VIREMENT, ESPECES restent valides). Les moyens de départ sont
 * créés confirmés d'office, « par défaut », version 1 au nom du système.
 */
return new class extends Migration
{
    public const DEFAUTS = [
        // code, libellé, type, ordre
        ['ORANGE_MONEY', 'Orange Money', 'MOBILE_MONEY', 1],
        ['MOOV_MONEY', 'Moov Africa Money', 'MOBILE_MONEY', 2],
        ['TELECEL_MONEY', 'Telecel Money', 'MOBILE_MONEY', 3],
        ['FASO_ARZEKA', 'Faso Arzeka', 'PASSERELLE', 4],
        ['VIREMENT', 'Virement bancaire', 'VIREMENT', 5],
        ['ESPECES', 'Espèces au guichet', 'ESPECES', 6],
    ];

    public function up(): void
    {
        Schema::create('moyens_paiement', function (Blueprint $table) {
            $table->id();
            $table->string('code', 20)->unique();
            $table->string('libelle', 100);
            $table->string('description', 300)->nullable();
            $table->string('type', 20);
            $table->unsignedInteger('ordre')->default(0);
            $table->boolean('actif')->default(true);
            $table->boolean('par_defaut')->default(false);
            $table->string('statut', 12)->default('VALIDE')->index();
            $table->foreignId('demande_par')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('valide_par')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('valide_le')->nullable();
            $table->text('motif_rejet')->nullable();
            $table->unsignedInteger('version')->default(0);
            $table->json('modification_demandee')->nullable();
            $table->timestamps();
        });

        $maintenant = now();
        foreach (self::DEFAUTS as [$code, $libelle, $type, $ordre]) {
            $id = DB::table('moyens_paiement')->insertGetId([
                'code' => $code, 'libelle' => $libelle, 'type' => $type, 'ordre' => $ordre, 'actif' => true, 'par_defaut' => true,
                'statut' => 'VALIDE', 'valide_le' => $maintenant, 'version' => 1, 'created_at' => $maintenant, 'updated_at' => $maintenant,
            ]);
            DB::table('referentiel_versions')->insert([
                'objet_type' => 'moyen_paiement', 'objet_id' => $id, 'numero' => 1, 'auteur_id' => null,
                'motif' => 'Valeur par défaut, confirmée d’office', 'created_at' => $maintenant,
                'parametres' => json_encode(['code' => $code, 'libelle' => $libelle, 'description' => null, 'type' => $type, 'ordre' => $ordre, 'actif' => true, 'statut' => 'VALIDE']),
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('moyens_paiement');
    }
};
