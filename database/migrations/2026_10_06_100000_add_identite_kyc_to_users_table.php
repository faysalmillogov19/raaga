<?php

use App\Enums\StatutKyc;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Identité et KYC des citoyens. Les citoyens se connectent par téléphone + OTP :
     * l'e-mail et le mot de passe deviennent facultatifs.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('email')->nullable()->change();
            $table->string('password')->nullable()->change();

            $table->string('telephone', 20)->nullable()->unique()->after('email');
            $table->string('nom')->nullable()->after('name');
            $table->string('prenoms')->nullable()->after('nom');
            $table->date('date_naissance')->nullable()->after('prenoms');
            $table->char('nationalite', 2)->nullable()->after('date_naissance');
            $table->char('pays_residence', 2)->nullable()->after('nationalite');
            $table->string('region')->nullable()->after('pays_residence');
            $table->string('statut_kyc', 20)->default(StatutKyc::NonSoumis->value)->index()->after('role');
            $table->unsignedTinyInteger('niveau_kyc')->default(0)->after('statut_kyc');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['telephone']);
            $table->dropIndex(['statut_kyc']);
            $table->dropColumn([
                'telephone', 'nom', 'prenoms', 'date_naissance', 'nationalite',
                'pays_residence', 'region', 'statut_kyc', 'niveau_kyc',
            ]);
        });
    }
};
