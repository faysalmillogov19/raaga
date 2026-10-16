<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Paiements manuels côté citoyen (virement, versement, espèces) : référence de paiement unique à reporter sur le
 * virement ou le bordereau, preuve jointe (avis de virement, bordereau ou reçu de caisse) contrôlée par le
 * CONTRÔLEUR DES PAIEMENTS. Tant qu'une preuve attend son contrôle, le délai de paiement est gelé.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('paiements', function (Blueprint $table) {
            $table->string('reference_versement', 20)->nullable()->unique();
            $table->string('preuve_chemin')->nullable();
            $table->string('preuve_nom', 200)->nullable();
            $table->string('preuve_mime', 100)->nullable();
            $table->timestamp('preuve_deposee_le')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('paiements', function (Blueprint $table) {
            $table->dropUnique(['reference_versement']);
            $table->dropColumn(['reference_versement', 'preuve_chemin', 'preuve_nom', 'preuve_mime', 'preuve_deposee_le']);
        });
    }
};
