<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Un paiement règle soit une souscription d'actions, soit la caution d'une candidature aux boutiques.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('paiements', function (Blueprint $table) {
            $table->foreignId('souscription_id')->nullable()->change();
            $table->foreignId('candidature_boutique_id')->nullable()->after('souscription_id')
                ->constrained('candidatures_boutiques')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('paiements', function (Blueprint $table) {
            $table->dropConstrainedForeignId('candidature_boutique_id');
            $table->foreignId('souscription_id')->nullable(false)->change();
        });
    }
};
