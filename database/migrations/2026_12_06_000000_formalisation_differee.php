<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Formalisation différée (réunion du 05/11/2026, lot 2) : une personne physique réserve sans IFU ; son dossier est « en
 * cours de formalisation » jusqu'à la validation de son IFU (attestation validée par l'ADMIN). Délai réglable par le
 * SUPER_ADMIN (6 mois par défaut) à compter de la réservation ; rappels à 2 mois, 1 mois et 15 jours de l'échéance ;
 * à l'échéance, alerte au back-office seulement. Récépissé de préinscription Raaga par inscription sans IFU : numéro
 * propre (RP-AAAA-000123, jamais sur la carte) et jeton de vérification (QR code).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('formalisation_debut_le')->nullable();
            $table->date('formalisation_echeance_le')->nullable();
            $table->json('formalisation_rappels')->nullable();
            $table->timestamp('formalisation_alerte_le')->nullable();
        });

        Schema::create('recepisses', function (Blueprint $table) {
            $table->id();
            $table->string('numero', 24)->unique();
            $table->string('jeton', 64)->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('inscription_boutique_id')->unique()->constrained('inscriptions_boutiques')->cascadeOnDelete();
            $table->foreignId('boutique_id')->constrained('boutiques');
            $table->timestamp('emis_le');
            $table->date('echeance_le');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recepisses');
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn(['formalisation_debut_le', 'formalisation_echeance_le', 'formalisation_rappels', 'formalisation_alerte_le']));
    }
};
