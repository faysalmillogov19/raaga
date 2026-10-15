<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Deux démarches sur le site public (investir, obtenir une boutique) :
     * - décisions de phase : « présentation des actions visible » et « souscription d'actions ouverte », proposées par le
     *   SUPER_ADMIN et confirmées (ou rejetées) par le VALIDATEUR ; chaque décision est conservée (historique) ;
     * - alertes SMS « souscription ouverte » : numéro et date du consentement.
     */
    public function up(): void
    {
        Schema::create('decisions_phase', function (Blueprint $table) {
            $table->id();
            $table->boolean('presentation_actions');
            $table->boolean('souscription_actions');
            $table->string('statut', 20)->index();
            $table->text('motif');
            $table->foreignId('propose_par')->constrained('users')->restrictOnDelete();
            $table->foreignId('decide_par')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('decide_le')->nullable();
            $table->text('motif_rejet')->nullable();
            $table->timestamps();
        });

        Schema::create('alertes_souscription', function (Blueprint $table) {
            $table->id();
            $table->string('telephone', 20)->unique();
            $table->timestamp('consentement_le');
            $table->timestamp('prevenu_le')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('alertes_souscription');
        Schema::dropIfExists('decisions_phase');
    }
};
