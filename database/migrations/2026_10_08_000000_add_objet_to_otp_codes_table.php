<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Un code n'est valable que pour l'usage demandé (connexion ou inscription).
     */
    public function up(): void
    {
        Schema::table('otp_codes', function (Blueprint $table) {
            $table->string('objet', 20)->default('connexion')->after('telephone');
            $table->index(['telephone', 'objet']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('otp_codes', function (Blueprint $table) {
            $table->dropIndex(['telephone', 'objet']);
            $table->dropColumn('objet');
        });
    }
};
