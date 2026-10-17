<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Révocation manuelle d'une carte : demandée par l'ADMIN (motif), confirmée ou rejetée par le VALIDATEUR. La carte reste
 * valide tant que la demande n'est pas confirmée.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cartes', function (Blueprint $table) {
            $table->timestamp('revocation_demandee_le')->nullable()->index();
            $table->foreignId('revocation_demandee_par')->nullable()->constrained('users')->nullOnDelete();
            $table->string('revocation_motif', 500)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('cartes', function (Blueprint $table) {
            $table->dropConstrainedForeignId('revocation_demandee_par');
            $table->dropColumn(['revocation_demandee_le', 'revocation_motif']);
        });
    }
};
