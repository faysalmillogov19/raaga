<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cartes d'accès Raaga (COMMERÇANT, ACTIONNAIRE, PERSONNEL) : une carte courante par personne et par type (colonne
 * « courante » à vrai, NULL pour les cartes révoquées ou expirées, ce qui garde l'unicité sur toutes les bases).
 * Champs Wallet prévus pour Google Wallet et Apple Wallet, non utilisés pour l'instant.
 * Utilisateurs : date d'expiration de la pièce d'identité (saisie au dépôt, vérifiée par l'ADMIN) et identité validée
 * (nom et prénoms figés quand l'ADMIN marque le dossier conforme : seuls affichés sur la carte).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cartes', function (Blueprint $table) {
            $table->id();
            $table->string('numero', 20)->nullable()->unique();
            $table->string('type', 20);
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('statut', 20)->index();
            $table->boolean('courante')->nullable();
            // Jeton du QR code : aléatoire, long, sans aucune donnée personnelle ; renouvelé à chaque réémission.
            $table->string('jeton', 80)->unique();
            $table->timestamp('emise_le')->nullable();
            $table->date('valide_jusqu_au')->nullable();
            $table->timestamp('suspendue_le')->nullable();
            $table->timestamp('revoquee_le')->nullable();
            $table->string('motif', 500)->nullable();
            // Contenu affiché (nom validé, emplacements, numéro d'actionnaire, fonction, photo) et version (régénération).
            $table->json('contenu')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->foreignId('emise_par')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('remplace_carte_id')->nullable()->constrained('cartes')->nullOnDelete();
            // Wallet (plus tard) : identifiants de l'objet Google Wallet et du pass Apple Wallet.
            $table->string('wallet_google_classe')->nullable();
            $table->string('wallet_google_objet')->nullable();
            $table->string('wallet_apple_type_pass')->nullable();
            $table->string('wallet_apple_serie')->nullable();
            $table->string('wallet_apple_jeton_auth', 80)->nullable();
            $table->timestamp('wallet_mis_a_jour_le')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'type', 'courante']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->date('piece_expire_le')->nullable();
            $table->timestamp('piece_avertie_le')->nullable();
            $table->json('identite_validee')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn(['piece_expire_le', 'piece_avertie_le', 'identite_validee']));
        Schema::dropIfExists('cartes');
    }
};
