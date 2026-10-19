<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Relances de la liste d'attente générale : boutiques libérées sans personne à retenir sur leur liste ; les
 * souscripteurs non attributaires de la campagne sont prévenus et peuvent manifester à nouveau leur intérêt jusqu'à la
 * fin du délai de relance. Ensuite, les boutiques toujours libres d'une campagne close sont signalées au RÉGULATEUR.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('relances_interet', function (Blueprint $table) {
            $table->id();
            $table->foreignId('campagne_boutiques_id')->constrained('campagnes_boutiques')->restrictOnDelete();
            $table->json('boutiques');
            $table->unsignedInteger('destinataires')->default(0);
            $table->timestamp('ouverte_le');
            $table->timestamp('fin_le');
            $table->timestamp('cloturee_le')->nullable();
            $table->json('restantes')->nullable();
            $table->timestamp('regulateur_notifie_le')->nullable();
            $table->timestamps();
            $table->index(['campagne_boutiques_id', 'cloturee_le'], 'relances_campagne');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('relances_interet');
    }
};
