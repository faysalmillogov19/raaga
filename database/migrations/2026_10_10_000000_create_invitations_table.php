<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Invitations des comptes back-office : lien personnel, valable 48 h, à usage unique.
     * Seule l'empreinte du jeton est conservée.
     */
    public function up(): void
    {
        Schema::create('invitations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->char('jeton_empreinte', 64)->unique();
            $table->timestamp('expire_le');
            $table->timestamp('utilisee_le')->nullable();
            $table->timestamp('annulee_le')->nullable();
            // Null = créée par la commande serveur (premier SUPER_ADMIN).
            $table->foreignId('cree_par')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('invitations');
    }
};
