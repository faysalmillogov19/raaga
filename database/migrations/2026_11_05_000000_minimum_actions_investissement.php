<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * RAAGA Invest : nombre minimum d'actions, à côté de la valeur indicative (1 par défaut). Saisi par le RÉGULATEUR,
 * confirmé par le VALIDATEUR ; prérempli dans chaque nouvelle tranche avec la valeur indicative.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('parametres_investissement', function (Blueprint $table) {
            $table->unsignedInteger('minimum_actions')->default(1)->after('valeur_action');
        });
    }

    public function down(): void
    {
        Schema::table('parametres_investissement', function (Blueprint $table) {
            $table->dropColumn('minimum_actions');
        });
    }
};
