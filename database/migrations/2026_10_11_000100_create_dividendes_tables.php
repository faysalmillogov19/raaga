<?php

use App\Support\AppendOnlyTable;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Distribution des dividendes : un paramétrage par exercice, ses versions figées,
     * et les blocages d'actions (succession, litige) dont le dividende est consigné.
     */
    public function up(): void
    {
        Schema::create('distributions_dividendes', function (Blueprint $table) {
            $table->id();
            $table->unsignedSmallInteger('exercice')->unique();
            $table->string('statut', 20)->index();

            // Décision de l'assemblée générale (PV en PDF obligatoire pour soumettre).
            $table->date('ag_date');
            $table->string('ag_pv_numero', 60);
            $table->string('pv_chemin')->nullable();
            $table->string('pv_nom_original')->nullable();
            $table->char('pv_empreinte', 64)->nullable();

            $table->unsignedBigInteger('resultat_distribuable');
            $table->string('mode_calcul', 20);
            $table->unsignedBigInteger('montant_global')->nullable();
            $table->unsignedBigInteger('dividende_brut_par_action')->nullable();

            $table->date('date_arrete');
            $table->date('date_mise_en_paiement');
            // Retenue fiscale (IRVM) en points de base : 1250 = 12,5 %.
            $table->unsignedSmallInteger('taux_irvm_pb');
            $table->string('frais_supportes_par', 20);
            $table->unsignedBigInteger('seuil_minimal');
            $table->json('canaux_autorises');
            $table->string('canal_defaut', 20);
            $table->unsignedTinyInteger('tentatives');
            $table->unsignedSmallInteger('delai_tentatives_heures');
            $table->unsignedSmallInteger('conservation_non_reclames_mois');
            $table->string('traitement_non_reclames', 30);

            $table->unsignedInteger('version')->default(1);
            $table->foreignId('cree_par')->constrained('users')->restrictOnDelete();
            $table->foreignId('soumis_par')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('soumise_le')->nullable();
            $table->foreignId('confirme_par')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('confirme_le')->nullable();
            $table->text('motif_rejet')->nullable();
            $table->timestamps();
        });

        Schema::create('distribution_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('distribution_id')->constrained('distributions_dividendes')->restrictOnDelete();
            $table->unsignedInteger('numero');
            $table->json('parametres');
            $table->foreignId('auteur_id')->constrained('users')->restrictOnDelete();
            $table->text('motif')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['distribution_id', 'numero']);
        });
        AppendOnlyTable::protect('distribution_versions');

        Schema::create('blocages_actions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('motif', 20);
            $table->text('commentaire')->nullable();
            $table->date('debut');
            $table->date('fin')->nullable();
            $table->foreignId('cree_par')->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->index(['user_id', 'debut', 'fin']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('blocages_actions');
        AppendOnlyTable::unprotect('distribution_versions');
        Schema::dropIfExists('distribution_versions');
        Schema::dropIfExists('distributions_dividendes');
    }
};
