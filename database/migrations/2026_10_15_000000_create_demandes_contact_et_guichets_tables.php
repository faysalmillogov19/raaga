<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Messages du formulaire « Nous contacter », en attendant le module de tickets d'assistance.
        Schema::create('demandes_contact', function (Blueprint $table) {
            $table->id();
            $table->string('numero', 20)->nullable()->unique();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('nom', 120);
            $table->string('telephone', 20);
            $table->string('email')->nullable();
            $table->string('objet', 20);
            $table->text('message');
            $table->string('statut', 20)->default('nouvelle')->index();
            $table->timestamps();
        });

        // Guichets agréés (souscription et paiement en espèces), affichés sur « Trouver un guichet ».
        Schema::create('guichets', function (Blueprint $table) {
            $table->id();
            $table->string('nom', 120);
            $table->string('region', 60);
            $table->string('commune', 80);
            $table->string('adresse', 300);
            $table->string('horaires', 200)->nullable();
            $table->string('telephone', 20)->nullable();
            $table->boolean('actif')->default(true);
            $table->timestamps();

            $table->index(['actif', 'region', 'commune']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('guichets');
        Schema::dropIfExists('demandes_contact');
    }
};
