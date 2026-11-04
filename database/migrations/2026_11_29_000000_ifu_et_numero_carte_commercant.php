<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * IFU obligatoire pour tous (03/11/2026) : un seul compte par numéro. Il devient le numéro de la carte de commerçant, que
 * garde aussi la carte réémise : le numéro n'est plus unique que parmi les cartes courantes (« courante » à vrai, NULL
 * pour les cartes révoquées ou expirées, ce qui garde l'unicité sur toutes les bases).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['ifu']);
            $table->unique('ifu');
        });
        Schema::table('cartes', function (Blueprint $table) {
            $table->dropUnique(['numero']);
            $table->unique(['numero', 'courante']);
        });
    }

    public function down(): void
    {
        Schema::table('cartes', function (Blueprint $table) {
            $table->dropUnique(['numero', 'courante']);
            $table->unique('numero');
        });
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['ifu']);
            $table->index('ifu');
        });
    }
};
