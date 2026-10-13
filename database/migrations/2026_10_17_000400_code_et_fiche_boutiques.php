<?php

use App\Models\Boutique;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Référencement des boutiques : code court du site et du type, fiche de la boutique (niveau, position, coffret,
 * planche, état physique) et code boutique SITE-NIVEAU-BLOC-NUMÉRO, unique et jamais réutilisé (suppression logique).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->string('code', 3)->nullable()->unique()->after('statut');
        });

        Schema::table('types_boutiques', function (Blueprint $table) {
            $table->string('code', 6)->nullable()->unique()->after('id');
        });

        Schema::table('boutiques', function (Blueprint $table) {
            // L'unicité porte désormais sur le code (le même numéro existe dans plusieurs blocs ou niveaux).
            $table->index('site_id');
            $table->dropUnique(['site_id', 'numero']);

            $table->string('code', 30)->nullable()->unique()->after('type_boutique_id');
            $table->string('niveau', 2)->nullable()->after('numero');
            $table->string('position', 10)->nullable()->after('surface');
            $table->string('coffret', 60)->nullable()->after('position');
            $table->string('planche', 60)->nullable()->after('coffret');
            $table->string('etat_physique', 20)->nullable()->after('planche');
            $table->softDeletes();
        });

        // Données existantes : le code est calculé quand tous ses éléments sont connus ; sinon la boutique est signalée.
        $sansNiveau = [];
        $incompletes = [];
        DB::table('boutiques')->join('sites', 'sites.id', '=', 'boutiques.site_id')
            ->select('boutiques.id', 'boutiques.numero', 'boutiques.niveau', 'boutiques.bloc', 'sites.code as site_code', 'sites.nom as site_nom')
            ->orderBy('boutiques.id')
            ->each(function ($b) use (&$sansNiveau, &$incompletes) {
                $libelle = $b->site_nom.' / '.$b->numero;
                if ($b->niveau === null) {
                    $sansNiveau[] = $libelle;

                    return;
                }
                $code = Boutique::composerCode($b->site_code, $b->niveau, $b->bloc, $b->numero);
                $code === null
                    ? $incompletes[] = $libelle
                    : DB::table('boutiques')->where('id', $b->id)->update(['code' => $code]);
            });

        if ($sansNiveau !== [] || $incompletes !== []) {
            Log::warning('Boutiques sans code : niveau, bloc, numéro ou code du site à renseigner dans « Sites et boutiques ».', [
                'sans_niveau' => count($sansNiveau), 'autres_elements_manquants' => count($incompletes),
                'boutiques' => array_slice([...$sansNiveau, ...$incompletes], 0, 200),
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('boutiques', function (Blueprint $table) {
            $table->dropUnique(['code']);
            $table->dropColumn(['code', 'niveau', 'position', 'coffret', 'planche', 'etat_physique', 'deleted_at']);
            $table->unique(['site_id', 'numero']);
            $table->dropIndex(['site_id']);
        });

        Schema::table('types_boutiques', function (Blueprint $table) {
            $table->dropUnique(['code']);
            $table->dropColumn('code');
        });

        Schema::table('sites', function (Blueprint $table) {
            $table->dropUnique(['code']);
            $table->dropColumn('code');
        });
    }
};
