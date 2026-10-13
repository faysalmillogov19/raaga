<?php

use App\Support\AppendOnlyTable;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Types de boutiques gérés par le GESTIONNAIRE DES SITES ET BOUTIQUES : unité du loyer, actif, « par défaut »,
 * validation par le VALIDATEUR (nouveau type, changement d'« attribuable par tirage ») et versions figées.
 * Les types déjà présents restent utilisables (statut VALIDE).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('types_boutiques', function (Blueprint $table) {
            $table->string('unite_loyer', 10)->default('m2')->after('description');
            $table->boolean('actif')->default(true)->after('attribuable');
            $table->boolean('par_defaut')->default(false)->after('actif');
            $table->string('statut', 12)->default('VALIDE')->index()->after('par_defaut');
            // Changement d'« attribuable par tirage » en attente de validation.
            $table->boolean('attribuable_demande')->nullable()->after('statut');
            $table->foreignId('demande_par')->nullable()->after('attribuable_demande')->constrained('users')->restrictOnDelete();
            $table->timestamp('demande_le')->nullable()->after('demande_par');
            $table->foreignId('valide_par')->nullable()->after('demande_le')->constrained('users')->restrictOnDelete();
            $table->timestamp('valide_le')->nullable()->after('valide_par');
            $table->text('motif_rejet')->nullable()->after('valide_le');
            $table->unsignedInteger('version')->default(0)->after('motif_rejet');
        });

        Schema::create('type_boutique_versions', function (Blueprint $table) {
            $table->id();
            // Sans clé étrangère : l'historique d'un type supprimé (jamais utilisé) est conservé.
            $table->unsignedBigInteger('type_boutique_id');
            $table->unsignedInteger('numero');
            $table->json('parametres');
            $table->foreignId('auteur_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('motif')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['type_boutique_id', 'numero']);
        });
        AppendOnlyTable::protect('type_boutique_versions');
    }

    public function down(): void
    {
        AppendOnlyTable::unprotect('type_boutique_versions');
        Schema::dropIfExists('type_boutique_versions');
        Schema::table('types_boutiques', function (Blueprint $table) {
            $table->dropConstrainedForeignId('demande_par');
            $table->dropConstrainedForeignId('valide_par');
            $table->dropColumn(['unite_loyer', 'actif', 'par_defaut', 'statut', 'attribuable_demande', 'demande_le', 'valide_le', 'motif_rejet', 'version']);
        });
    }
};
