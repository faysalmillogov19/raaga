<?php

use App\Enums\StatutTranche;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Une tranche et tous ses paramètres de souscription. Montants en FCFA entiers.
     */
    public function up(): void
    {
        Schema::create('tranches', function (Blueprint $table) {
            $table->id();
            $table->string('statut', 20)->default(StatutTranche::Brouillon->value)->index();

            // Offre
            $table->string('nom');
            $table->unsignedBigInteger('valeur_unitaire')->default(10000);
            $table->unsignedInteger('min_actions_par_souscription')->default(1);
            $table->unsignedBigInteger('plafond_actions_par_personne')->nullable();
            $table->unsignedBigInteger('objectif_montant');
            $table->dateTime('date_ouverture');
            $table->dateTime('date_cloture');
            $table->json('publics_autorises');

            // Saisie
            $table->string('mode_saisie', 40);
            $table->boolean('souscriptions_multiples')->default(true);

            // Sursouscription
            $table->string('regle_arret', 40);
            $table->unsignedSmallInteger('pourcentage_depassement_max')->nullable();
            $table->string('methode_allocation', 40);
            $table->unsignedBigInteger('seuil_garanti_petits')->nullable();
            $table->unsignedTinyInteger('plafond_concentration_pct')->nullable();

            // Tranche non atteinte
            $table->unsignedBigInteger('seuil_reussite_montant')->nullable();
            $table->string('regle_si_echec', 40);
            $table->unsignedSmallInteger('jours_prolongation')->nullable();
            $table->unsignedSmallInteger('delai_remboursement_jours');

            // Frais (POURCENTAGE : valeur en points de base, 100 = 1 %)
            $table->string('frais_paiement_supportes_par', 20);
            $table->string('frais_souscription_type', 20);
            $table->unsignedBigInteger('frais_souscription_valeur')->default(0);

            // Paiement & conformité
            $table->json('canaux_actifs');
            $table->unsignedSmallInteger('delai_paiement_heures')->default(48);
            $table->unsignedBigInteger('seuil_kyc_renforce')->nullable();
            $table->unsignedBigInteger('seuil_revue_conformite')->nullable();

            // Workflow
            $table->unsignedInteger('version')->default(1);
            $table->foreignId('cree_par')->constrained('users')->restrictOnDelete();
            $table->foreignId('soumis_par')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('soumise_le')->nullable();
            $table->foreignId('valide_par')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('validee_le')->nullable();
            $table->text('motif_rejet')->nullable();
            $table->timestamp('ouverte_le')->nullable();
            $table->timestamp('cloturee_le')->nullable();
            $table->timestamp('allouee_le')->nullable();
            $table->timestamp('annulee_le')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tranches');
    }
};
