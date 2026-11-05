<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Greffes d'immatriculation au RCCM (réunion du 05/11/2026, validation du lot 1) : liste administrable par le
 * SUPER_ADMIN (Ouagadougou, Bobo-Dioulasso, et « Autre (préciser) » jamais désactivée). Le titulaire choisit dans la
 * liste ; avec « Autre », sa précision est gardée (rccm_greffe_autre) et visible de l'ADMIN pour compléter la liste.
 * Les greffes déjà saisis en texte libre sont rattachés à la liste ou gardés comme précision d'« Autre ».
 */
return new class extends Migration
{
    public const LISTE = [
        ['OUAGADOUGOU', 'Tribunal de commerce de Ouagadougou', 1],
        ['BOBO_DIOULASSO', 'Tribunal de commerce de Bobo-Dioulasso', 2],
        ['AUTRE', 'Autre (préciser)', 999],
    ];

    public function up(): void
    {
        Schema::create('greffes', function (Blueprint $table) {
            $table->id();
            $table->string('code', 40)->unique();
            $table->string('libelle', 160)->unique();
            $table->unsignedInteger('ordre')->default(0);
            $table->boolean('actif')->default(true);
            $table->timestamps();
        });
        foreach (self::LISTE as [$code, $libelle, $ordre]) {
            DB::table('greffes')->insert(['code' => $code, 'libelle' => $libelle, 'ordre' => $ordre, 'actif' => true, 'created_at' => now(), 'updated_at' => now()]);
        }

        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('rccm_greffe_id')->nullable()->after('rccm_immatricule_le')->constrained('greffes')->nullOnDelete();
            $table->renameColumn('rccm_greffe', 'rccm_greffe_autre');
        });

        // Greffes déjà saisis en texte libre : rattachés à la liste s'ils y figurent, sinon « Autre » avec leur texte.
        $ids = DB::table('greffes')->pluck('id', 'libelle');
        foreach (DB::table('users')->whereNotNull('rccm_greffe_autre')->get(['id', 'rccm_greffe_autre']) as $u) {
            $connu = $ids->first(fn ($id, $libelle) => mb_strtolower($libelle) === mb_strtolower(trim($u->rccm_greffe_autre)));
            DB::table('users')->where('id', $u->id)->update($connu
                ? ['rccm_greffe_id' => $connu, 'rccm_greffe_autre' => null]
                : ['rccm_greffe_id' => $ids['Autre (préciser)'], 'rccm_greffe_autre' => trim($u->rccm_greffe_autre)]);
        }
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('rccm_greffe_id');
            $table->renameColumn('rccm_greffe_autre', 'rccm_greffe');
        });
        Schema::dropIfExists('greffes');
    }
};
