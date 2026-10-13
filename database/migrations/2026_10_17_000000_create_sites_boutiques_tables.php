<?php

use App\Models\Role;
use App\Support\AppendOnlyTable;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Référentiel des sites du marché et de leurs boutiques, créé en amont des campagnes d'attribution.
 * Tenu par le GESTIONNAIRE DES SITES, confirmé par le VALIDATEUR (workflow commun, versions figées).
 */
return new class extends Migration
{
    public function up(): void
    {
        // Nouveau profil « Gestionnaire des sites ».
        Role::synchroniser();

        Schema::create('types_boutiques', function (Blueprint $table) {
            $table->id();
            $table->string('libelle', 80)->unique();
            $table->string('description', 300)->nullable();
            $table->unsignedSmallInteger('ordre')->default(0);
            $table->timestamps();
        });

        Schema::create('sites', function (Blueprint $table) {
            $table->id();
            $table->string('statut', 20)->index();
            $table->string('nom', 150);
            $table->string('region', 60);
            $table->string('commune', 80);
            $table->string('adresse', 300);
            $table->text('description')->nullable();
            $table->unsignedInteger('version')->default(0);
            $table->foreignId('cree_par')->constrained('users')->restrictOnDelete();
            $table->foreignId('soumis_par')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('soumis_le')->nullable();
            $table->foreignId('valide_par')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('valide_le')->nullable();
            $table->text('motif_rejet')->nullable();
            $table->timestamps();
        });

        Schema::create('site_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('numero');
            $table->json('parametres');
            $table->foreignId('auteur_id')->constrained('users')->restrictOnDelete();
            $table->string('motif')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['site_id', 'numero']);
        });
        AppendOnlyTable::protect('site_versions');

        Schema::create('boutiques', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->constrained()->restrictOnDelete();
            $table->foreignId('type_boutique_id')->constrained('types_boutiques')->restrictOnDelete();
            $table->string('numero', 30);
            $table->string('bloc', 40)->nullable();
            $table->decimal('surface', 7, 2)->nullable();
            // DISPONIBLE, EN_CAMPAGNE, ATTRIBUEE, RETIREE.
            $table->string('statut', 20)->default('DISPONIBLE')->index();
            $table->timestamps();

            $table->unique(['site_id', 'numero']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('boutiques');
        AppendOnlyTable::unprotect('site_versions');
        Schema::dropIfExists('site_versions');
        Schema::dropIfExists('sites');
        Schema::dropIfExists('types_boutiques');
    }
};
