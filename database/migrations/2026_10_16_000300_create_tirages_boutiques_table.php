<?php

use App\Support\AppendOnlyTable;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tirage au sort des boutiques, par catégorie : graine aléatoire publiée, empreinte de la liste des
 * participants, ordre obtenu (vérifiable par quiconque dispose de la graine et des références). Ajout seul.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tirages_boutiques', function (Blueprint $table) {
            $table->id();
            $table->foreignId('campagne_boutiques_id')->constrained('campagnes_boutiques')->restrictOnDelete();
            $table->foreignId('categorie_boutique_id')->constrained('categories_boutiques')->restrictOnDelete();
            $table->char('graine', 64);
            $table->char('empreinte_participants', 64);
            $table->unsignedInteger('nombre_boutiques');
            $table->unsignedInteger('nombre_participants');
            $table->json('ordre');
            $table->foreignId('tire_par')->constrained('users')->restrictOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['campagne_boutiques_id', 'categorie_boutique_id'], 'tirages_boutiques_categorie_unique');
        });
        AppendOnlyTable::protect('tirages_boutiques');
    }

    public function down(): void
    {
        AppendOnlyTable::unprotect('tirages_boutiques');
        Schema::dropIfExists('tirages_boutiques');
    }
};
