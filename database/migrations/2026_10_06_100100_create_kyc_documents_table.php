<?php

use App\Enums\StatutDocumentKyc;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('kyc_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('type', 20);
            $table->string('face', 10)->nullable();
            // Chemin relatif sur le disque privé (hors du dossier public).
            $table->string('chemin_fichier');
            $table->string('nom_original')->nullable();
            $table->string('mime', 100);
            $table->unsignedInteger('taille');
            $table->char('empreinte_sha256', 64);
            $table->string('statut', 20)->default(StatutDocumentKyc::EnAttente->value)->index();
            $table->text('commentaire')->nullable();
            $table->foreignId('televerse_par')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('valide_par')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('valide_le')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'type']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('kyc_documents');
    }
};
