<?php

use App\Support\AppendOnlyTable;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Attribution des boutiques du marché : campagnes de candidature (indépendantes des actions),
 * catégories de boutiques avec leur caution, versions figées du paramétrage (RÉGULATEUR → VALIDATEUR).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('campagnes_boutiques', function (Blueprint $table) {
            $table->id();
            $table->string('statut', 20)->index();
            $table->string('nom', 150);
            $table->text('description')->nullable();
            $table->dateTime('date_ouverture');
            $table->dateTime('date_cloture');
            $table->unsignedSmallInteger('delai_paiement_heures');
            $table->json('canaux_actifs');
            $table->unsignedInteger('version')->default(0);
            $table->foreignId('cree_par')->constrained('users')->restrictOnDelete();
            $table->foreignId('soumis_par')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('soumise_le')->nullable();
            $table->foreignId('valide_par')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('validee_le')->nullable();
            $table->text('motif_rejet')->nullable();
            $table->timestamp('ouverte_le')->nullable();
            $table->timestamp('cloturee_le')->nullable();
            $table->timestamp('attribuee_le')->nullable();
            $table->timestamps();
        });

        Schema::create('categories_boutiques', function (Blueprint $table) {
            $table->id();
            $table->foreignId('campagne_boutiques_id')->constrained('campagnes_boutiques')->cascadeOnDelete();
            $table->unsignedSmallInteger('ordre');
            $table->string('libelle', 80);
            $table->string('description', 300)->nullable();
            $table->unsignedInteger('nombre_boutiques');
            $table->unsignedBigInteger('caution');
            $table->timestamps();
        });

        Schema::create('campagne_boutiques_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('campagne_boutiques_id')->constrained('campagnes_boutiques')->restrictOnDelete();
            $table->unsignedInteger('numero');
            $table->json('parametres');
            $table->foreignId('auteur_id')->constrained('users')->restrictOnDelete();
            $table->string('motif')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['campagne_boutiques_id', 'numero']);
        });
        AppendOnlyTable::protect('campagne_boutiques_versions');
    }

    public function down(): void
    {
        AppendOnlyTable::unprotect('campagne_boutiques_versions');
        Schema::dropIfExists('campagne_boutiques_versions');
        Schema::dropIfExists('categories_boutiques');
        Schema::dropIfExists('campagnes_boutiques');
    }
};
