<?php

use App\Models\Role;
use App\Support\AppendOnlyTable;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Investisseurs institutionnels (actions A) : institutions et leurs utilisateurs, dossier KYB,
     * conventions de souscription (versions figées), appels de fonds, déclarations de virement et
     * registre des actions A (ajout seul, chaîné), distinct du registre des actions B.
     */
    public function up(): void
    {
        Schema::create('institutions', function (Blueprint $table) {
            $table->id();
            $table->string('denomination', 200);
            $table->string('sigle', 40)->nullable();
            $table->string('forme_juridique', 80)->nullable();
            $table->string('rccm', 60)->nullable();
            $table->string('ifu', 40)->nullable();
            $table->string('adresse', 300)->nullable();
            $table->string('secteur', 120)->nullable();
            $table->string('statut', 20)->index();
            $table->string('motif_suspension', 500)->nullable();

            // Compte émetteur des virements (RIB vérifié avec le dossier KYB).
            $table->string('rib_titulaire', 200)->nullable();
            $table->string('rib_banque', 120)->nullable();
            $table->string('rib_numero', 60)->nullable();

            // Interlocuteur dédié chez Raaga (configuré par le SUPER_ADMIN).
            $table->string('interlocuteur_nom', 120)->nullable();
            $table->string('interlocuteur_email')->nullable();
            $table->string('interlocuteur_telephone', 30)->nullable();

            $table->foreignId('cree_par')->nullable()->constrained('users');
            $table->timestamp('verifiee_le')->nullable();
            $table->timestamps();
        });

        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('institution_id')->nullable()->after('role_id')->constrained('institutions');
        });

        Schema::create('institution_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('institution_id')->constrained('institutions');
            $table->string('type', 40);
            $table->string('chemin');
            $table->string('nom_original');
            $table->char('empreinte', 64);
            $table->unsignedInteger('taille');
            $table->foreignId('depose_par')->constrained('users');
            $table->string('statut', 20)->index();
            $table->string('motif_rejet', 500)->nullable();
            $table->foreignId('verifie_par')->nullable()->constrained('users');
            $table->timestamp('verifie_le')->nullable();
            $table->timestamps();
            $table->index(['institution_id', 'type']);
        });

        Schema::create('conventions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('institution_id')->constrained('institutions');
            $table->string('reference', 60)->unique();
            $table->string('statut', 20)->index();
            $table->unsignedBigInteger('nb_actions');
            $table->unsignedBigInteger('prix_unitaire');
            $table->unsignedBigInteger('montant_engage');
            $table->json('echeancier');
            $table->text('droits_particuliers')->nullable();
            $table->string('mode_inscription', 20);

            $table->string('pdf_chemin')->nullable();
            $table->string('pdf_nom_original')->nullable();
            $table->char('pdf_empreinte', 64)->nullable();

            $table->unsignedInteger('version')->default(0);
            $table->foreignId('cree_par')->constrained('users');
            $table->foreignId('soumis_par')->nullable()->constrained('users');
            $table->timestamp('soumise_le')->nullable();
            $table->foreignId('confirme_par')->nullable()->constrained('users');
            $table->timestamp('confirmee_le')->nullable();
            $table->string('motif_rejet', 1000)->nullable();

            // Signature : électronique (TOTP + horodatage + empreinte) ou dépôt du PDF signé attesté par le VALIDATEUR.
            $table->string('signature_mode', 20)->nullable();
            $table->foreignId('signee_par')->nullable()->constrained('users');
            $table->timestamp('signee_le')->nullable();
            $table->char('signature_empreinte', 64)->nullable();
            $table->string('signature_pdf_chemin')->nullable();
            $table->char('signature_pdf_empreinte', 64)->nullable();
            $table->timestamp('signature_deposee_le')->nullable();
            $table->foreignId('reception_attestee_par')->nullable()->constrained('users');
            $table->timestamps();
        });

        Schema::create('convention_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('convention_id')->constrained('conventions');
            $table->unsignedInteger('numero');
            $table->json('parametres');
            $table->foreignId('auteur_id')->constrained('users');
            $table->string('motif', 500)->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->unique(['convention_id', 'numero']);
        });
        AppendOnlyTable::protect('convention_versions');

        Schema::create('appels_fonds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('convention_id')->constrained('conventions');
            $table->foreignId('institution_id')->constrained('institutions');
            $table->unsignedSmallInteger('numero');
            $table->string('libelle', 160);
            $table->date('echeance');
            $table->unsignedBigInteger('montant');
            $table->unsignedBigInteger('montant_recu')->default(0);
            $table->string('statut', 20)->index();
            $table->string('reference_virement', 40)->unique();
            $table->timestamp('recu_le')->nullable();
            $table->json('rappels')->nullable();
            $table->timestamps();
            $table->unique(['convention_id', 'numero']);
        });

        Schema::create('declarations_virement', function (Blueprint $table) {
            $table->id();
            $table->foreignId('appel_fonds_id')->constrained('appels_fonds');
            $table->foreignId('institution_id')->constrained('institutions');
            $table->date('date_virement');
            $table->unsignedBigInteger('montant');
            $table->string('reference', 60);
            $table->string('avis_chemin')->nullable();
            $table->char('avis_empreinte', 64)->nullable();
            $table->foreignId('declare_par')->constrained('users');
            $table->string('statut', 20)->index();
            $table->unsignedBigInteger('montant_recu')->nullable();
            $table->date('recu_le')->nullable();
            $table->string('reference_releve', 80)->nullable();
            $table->string('motif_rejet', 500)->nullable();
            $table->foreignId('rapproche_par')->nullable()->constrained('users');
            $table->timestamp('rapproche_le')->nullable();
            $table->timestamps();
        });

        Schema::create('lignes_registre_a', function (Blueprint $table) {
            $table->id();
            $table->string('numero_certificat', 30)->unique();
            $table->foreignId('institution_id')->constrained('institutions');
            $table->foreignId('convention_id')->constrained('conventions');
            $table->foreignId('appel_fonds_id')->nullable()->constrained('appels_fonds');
            $table->unsignedBigInteger('nb_actions');
            $table->unsignedBigInteger('numero_debut');
            $table->unsignedBigInteger('numero_fin');
            $table->date('date_inscription');
            $table->string('attestation_chemin');
            $table->char('attestation_empreinte', 64);
            $table->foreignId('inscrit_par')->constrained('users');
            // Chaînage : chaque ligne scelle la précédente (toute altération casse la chaîne).
            $table->char('empreinte_precedente', 64)->nullable();
            $table->char('empreinte', 64)->unique();
            $table->timestamp('created_at')->useCurrent();
        });
        AppendOnlyTable::protect('lignes_registre_a');

        // Rôles INSTITUTION_SIGNATAIRE et INSTITUTION_LECTEUR dans la table des rôles.
        Role::synchroniser();
    }

    public function down(): void
    {
        AppendOnlyTable::unprotect('lignes_registre_a');
        Schema::dropIfExists('lignes_registre_a');
        Schema::dropIfExists('declarations_virement');
        Schema::dropIfExists('appels_fonds');
        AppendOnlyTable::unprotect('convention_versions');
        Schema::dropIfExists('convention_versions');
        Schema::dropIfExists('conventions');
        Schema::dropIfExists('institution_documents');
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('institution_id');
        });
        Schema::dropIfExists('institutions');
    }
};
