<?php

use Database\Seeders\RegionsSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Comité restreint du 03/11/2026.
 *  - Identifiants propres à Raaga (RG-F, RG-R) supprimés : l'IFU les remplace.
 *  - Données de la nouvelle carte de commerçant : sexe et lieu de naissance (vérification d'identité), groupe sanguin
 *    (déclaratif, chiffré, avec consentement daté), personne à prévenir.
 *  - Découpage administratif : seules les communes servent ; la région texte des comptes disparaît, les sites et les
 *    guichets sont rattachés à leur commune, aucune trace de l'ancien découpage n'est gardée. Chef-lieu des régions.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['identifiant_fiscal_raaga']);
            $table->dropUnique(['numero_registre_raaga']);
            $table->dropColumn(['identifiant_fiscal_raaga', 'numero_registre_raaga', 'identifiants_raaga_le', 'region']);
            $table->string('sexe', 1)->nullable();
            $table->string('lieu_naissance', 100)->nullable();
            $table->text('groupe_sanguin')->nullable();
            $table->timestamp('groupe_sanguin_consenti_le')->nullable();
            $table->string('personne_prevenir_nom', 150)->nullable();
            $table->string('personne_prevenir_telephone', 20)->nullable();
        });

        foreach (['regions', 'provinces', 'communes'] as $table) {
            Schema::table($table, fn (Blueprint $t) => $t->dropColumn(['ancien_code', 'ancien_nom']));
        }
        Schema::table('regions', fn (Blueprint $t) => $t->string('chef_lieu', 100)->nullable());
        foreach (RegionsSeeder::CHEFS_LIEUX as $code => $chefLieu) {
            DB::table('regions')->where('code', $code)->update(['chef_lieu' => $chefLieu]);
        }
        // Nom officiel de la région BF49.
        DB::table('regions')->where('code', 'BF49')->update(['nom' => 'Kuilsé']);

        // Sites et guichets : rattachés à la commune de même nom (données de test seulement à cette date).
        Schema::table('sites', fn (Blueprint $t) => $t->foreignId('commune_id')->nullable()->constrained('communes')->nullOnDelete());
        Schema::table('guichets', fn (Blueprint $t) => $t->foreignId('commune_id')->nullable()->constrained('communes')->nullOnDelete());
        $commune = fn (?string $nom) => $nom ? DB::table('communes')->whereRaw('lower(nom) = ?', [mb_strtolower(trim($nom))])->value('id') : null;
        foreach (DB::table('sites')->get(['id', 'ville', 'commune']) as $s) {
            DB::table('sites')->where('id', $s->id)->update(['commune_id' => $commune($s->commune) ?? $commune($s->ville)]);
        }
        foreach (DB::table('guichets')->get(['id', 'commune']) as $g) {
            DB::table('guichets')->where('id', $g->id)->update(['commune_id' => $commune($g->commune)]);
        }
        Schema::table('sites', fn (Blueprint $t) => $t->dropColumn(['region', 'commune']));
        Schema::table('guichets', fn (Blueprint $t) => $t->dropColumn(['region', 'commune']));
    }

    public function down(): void
    {
        Schema::table('guichets', function (Blueprint $t) {
            $t->dropConstrainedForeignId('commune_id');
            $t->string('region', 60)->nullable();
            $t->string('commune', 80)->nullable();
        });
        Schema::table('sites', function (Blueprint $t) {
            $t->dropConstrainedForeignId('commune_id');
            $t->string('region', 60)->nullable();
            $t->string('commune', 80)->nullable();
        });
        Schema::table('regions', fn (Blueprint $t) => $t->dropColumn('chef_lieu'));
        foreach (['regions', 'provinces', 'communes'] as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->string('ancien_code', 12)->nullable();
                $t->string('ancien_nom', 100)->nullable();
            });
        }
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['sexe', 'lieu_naissance', 'groupe_sanguin', 'groupe_sanguin_consenti_le', 'personne_prevenir_nom', 'personne_prevenir_telephone']);
            $table->string('region', 60)->nullable();
            $table->string('identifiant_fiscal_raaga', 20)->nullable()->unique();
            $table->string('numero_registre_raaga', 24)->nullable()->unique();
            $table->timestamp('identifiants_raaga_le')->nullable();
        });
    }
};
