<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Identifiants du commerçant propres à Raaga (02/11/2026) : identifiant fiscal Raaga (RG-F-000123) et numéro de registre
 * Raaga (RG-R-2026-000123), attribués à la première attribution d'une boutique. Distincts de l'IFU et du RCCM officiels
 * déclarés par une personne morale (colonnes ifu et rccm). Commerçants déjà attributaires : raaga:identifiants-commercants.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('identifiant_fiscal_raaga', 20)->nullable()->unique();
            $table->string('numero_registre_raaga', 24)->nullable()->unique();
            $table->timestamp('identifiants_raaga_le')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['identifiant_fiscal_raaga']);
            $table->dropUnique(['numero_registre_raaga']);
            $table->dropColumn(['identifiant_fiscal_raaga', 'numero_registre_raaga', 'identifiants_raaga_le']);
        });
    }
};
