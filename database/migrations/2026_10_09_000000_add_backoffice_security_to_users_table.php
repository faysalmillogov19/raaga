<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Sécurité des comptes back-office : désactivation (jamais de suppression), double
     * authentification TOTP, codes de secours, verrouillage après échecs, journal des connexions.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('actif')->default(true)->after('niveau_kyc');
            $table->text('totp_secret')->nullable()->after('remember_token');
            $table->timestamp('totp_active_le')->nullable()->after('totp_secret');
            $table->unsignedBigInteger('totp_dernier_pas')->nullable()->after('totp_active_le');
            $table->text('codes_secours')->nullable()->after('totp_dernier_pas');
            $table->unsignedTinyInteger('echecs_connexion')->default(0)->after('codes_secours');
            $table->timestamp('verrouille_jusqua')->nullable()->after('echecs_connexion');
            $table->timestamp('derniere_connexion_le')->nullable()->after('verrouille_jusqua');
        });

        Schema::create('connexions_backoffice', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->restrictOnDelete();
            // Adresse saisie (même inconnue) pour repérer les tentatives sur des comptes inexistants.
            $table->string('email');
            $table->string('etape', 20);
            $table->boolean('succes');
            $table->string('motif', 40)->nullable();
            $table->string('ip', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamp('created_at')->useCurrent()->index();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('connexions_backoffice');

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'actif', 'totp_secret', 'totp_active_le', 'totp_dernier_pas', 'codes_secours',
                'echecs_connexion', 'verrouille_jusqua', 'derniere_connexion_le',
            ]);
        });
    }
};
