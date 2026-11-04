<?php

use App\Models\Carte;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Code de contrôle imprimé sur la carte (comité restreint du 03/11/2026) : calculé avec une clé du serveur à partir des
 * données imprimées, il remplace la signature et le cachet de l'autorité. Gardé sur la carte pour la recherche au
 * guichet ; il change à chaque nouvelle version. Cartes déjà émises : code calculé ici.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cartes', fn (Blueprint $t) => $t->string('code_controle', 9)->nullable()->index());
        foreach (DB::table('cartes')->get(['id', 'numero', 'version', 'valide_jusqu_au', 'contenu']) as $c) {
            DB::table('cartes')->where('id', $c->id)->update(['code_controle' => Carte::calculerCode($c->numero, (int) $c->version,
                $c->valide_jusqu_au ? substr((string) $c->valide_jusqu_au, 0, 10) : null, json_decode((string) $c->contenu, true) ?: [])]);
        }
    }

    public function down(): void
    {
        Schema::table('cartes', function (Blueprint $t) {
            $t->dropIndex(['code_controle']);
            $t->dropColumn('code_controle');
        });
    }
};
