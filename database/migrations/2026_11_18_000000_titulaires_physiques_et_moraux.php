<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Pièces des personnes physiques et morales (17/10/2026) :
 *  - type de titulaire choisi à l'inscription (PHYSIQUE ou MORALE), raison sociale et forme d'une personne morale ;
 *  - identifiants contrôlés : numéro de pièce (physique, représentant légal), IFU et RCCM (morale) ;
 *  - numéro et date d'expiration portés par chaque pièce ;
 *  - date à laquelle un dossier ancien a été marqué « pièces à mettre à jour » (rien n'est supprimé) ;
 *  - « NIF » renommé « IFU » partout (pièces déposées, réservations).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('type_titulaire', 10)->default('PHYSIQUE');
            $table->string('raison_sociale', 200)->nullable();
            $table->string('forme_morale', 20)->nullable();
            $table->string('numero_piece', 40)->nullable()->index();
            $table->string('ifu', 40)->nullable()->index();
            $table->string('rccm', 60)->nullable();
            $table->timestamp('kyc_maj_demandee_le')->nullable();
        });
        Schema::table('kyc_documents', function (Blueprint $table) {
            $table->string('numero', 60)->nullable();
            $table->date('expire_le')->nullable();
        });

        DB::table('kyc_documents')->where('type', 'NIF')->update(['type' => 'IFU']);
        DB::table('reservations_boutiques')->where('type_piece', 'NIF')->update(['type_piece' => 'IFU']);
    }

    public function down(): void
    {
        DB::table('kyc_documents')->where('type', 'IFU')->update(['type' => 'NIF']);
        DB::table('reservations_boutiques')->where('type_piece', 'IFU')->update(['type_piece' => 'NIF']);

        Schema::table('kyc_documents', function (Blueprint $table) {
            $table->dropColumn(['numero', 'expire_le']);
        });
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['numero_piece']);
            $table->dropIndex(['ifu']);
            $table->dropColumn(['type_titulaire', 'raison_sociale', 'forme_morale', 'numero_piece', 'ifu', 'rccm', 'kyc_maj_demandee_le']);
        });
    }
};
