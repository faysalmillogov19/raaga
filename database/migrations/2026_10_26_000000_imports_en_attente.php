<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Imports de boutiques déposés mais pas encore confirmés : l'aperçu reste « En attente de confirmation » dans le
     * journal Imports (bouton « Reprendre »), expire au bout de 24 heures et est annulé par un nouvel import du même site.
     */
    public function up(): void
    {
        Schema::create('imports_en_attente', function (Blueprint $table) {
            $table->id();
            $table->string('entite', 20)->default('boutiques');
            // Site choisi ; null : import de plusieurs sites, le site de chaque boutique étant lu dans le fichier.
            $table->foreignId('site_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('chemin');
            $table->string('nom', 150);
            $table->string('empreinte', 64);
            $table->string('statut', 12)->index();
            $table->timestamp('expire_le');
            $table->timestamp('termine_le')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('imports_en_attente');
    }
};
