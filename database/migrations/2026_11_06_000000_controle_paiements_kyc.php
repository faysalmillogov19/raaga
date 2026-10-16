<?php

use App\Support\AppendOnlyTable;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CONTRÔLEUR DES PAIEMENTS et vérification d'identité par l'ADMIN :
 *  - contrôle d'un paiement manuel (virement, versement, espèces) : reçu, incomplet ou rejeté, avec un motif ;
 *  - relances (automatiques ou manuelles), tracées en ajout seul ;
 *  - corrections de paiements déjà confirmés et remboursements, confirmés par le VALIDATEUR ;
 *  - vérifications d'identité : conforme, ou douteux (proposition d'annulation confirmée ou rejetée par le VALIDATEUR).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('paiements', function (Blueprint $table) {
            $table->string('controle', 20)->nullable()->index();
            $table->text('controle_motif')->nullable();
            $table->string('controle_reference', 100)->nullable();
            $table->foreignId('controle_par')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('controle_le')->nullable();
        });

        Schema::create('relances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('souscription_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('candidature_boutique_id')->nullable()->constrained('candidatures_boutiques')->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            // ECHEANCE (24 h avant la fin du délai), EXPIRATION, MANUELLE.
            $table->string('type', 20);
            $table->json('canaux');
            // Null : relance automatique (tâche planifiée).
            $table->foreignId('auteur_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->text('message')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['souscription_id', 'type'], 'relances_souscription_type');
            $table->index(['candidature_boutique_id', 'type'], 'relances_candidature_type');
        });
        AppendOnlyTable::protect('relances');

        Schema::create('corrections_paiement', function (Blueprint $table) {
            $table->id();
            $table->foreignId('paiement_id')->constrained()->restrictOnDelete();
            $table->text('motif');
            $table->string('statut', 20)->default('PROPOSEE')->index();
            $table->foreignId('propose_par')->constrained('users')->restrictOnDelete();
            $table->timestamp('propose_le');
            $table->foreignId('decide_par')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('decide_le')->nullable();
            $table->text('motif_decision')->nullable();
            $table->timestamps();
        });

        Schema::create('remboursements', function (Blueprint $table) {
            $table->id();
            $table->string('reference', 24)->unique();
            $table->foreignId('souscription_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('candidature_boutique_id')->nullable()->constrained('candidatures_boutiques')->restrictOnDelete();
            $table->foreignId('paiement_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('montant');
            // ANNULATION_IDENTITE (dossier douteux confirmé), CORRECTION…
            $table->string('origine', 30);
            // A_EFFECTUER → PROPOSE (exécution déclarée par le contrôleur) → EFFECTUE (confirmé par le VALIDATEUR).
            $table->string('statut', 20)->default('A_EFFECTUER')->index();
            $table->string('mode', 40)->nullable();
            $table->string('reference_operation', 100)->nullable();
            $table->foreignId('propose_par')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('propose_le')->nullable();
            $table->foreignId('valide_par')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('valide_le')->nullable();
            $table->text('motif_rejet')->nullable();
            $table->timestamps();
        });

        Schema::create('verifications_identite', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            // CONFORME, ou DOUTEUX (proposition d'annulation).
            $table->string('decision', 20);
            $table->text('motif')->nullable();
            $table->foreignId('decide_par')->constrained('users')->restrictOnDelete();
            // CONFORME, ANNULATION_PROPOSEE, ANNULATION_CONFIRMEE, ANNULATION_REJETEE.
            $table->string('statut', 30)->index();
            $table->foreignId('valide_par')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('valide_le')->nullable();
            $table->text('motif_validateur')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('verifications_identite');
        Schema::dropIfExists('remboursements');
        Schema::dropIfExists('corrections_paiement');
        Schema::dropIfExists('relances');
        Schema::table('paiements', function (Blueprint $table) {
            $table->dropConstrainedForeignId('controle_par');
            $table->dropColumn(['controle', 'controle_motif', 'controle_reference', 'controle_le']);
        });
    }
};
