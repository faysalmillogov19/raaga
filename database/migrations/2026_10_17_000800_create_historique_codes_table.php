<?php

use App\Support\AppendOnlyTable;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Historique des codes : quand le code d'un site en brouillon ou en révision change, l'ancien code du site et ceux
 * de ses boutiques sont conservés ici (ajout seul). Un ancien code n'est jamais réutilisé, et un fichier qui le porte
 * encore est refusé avec le nouveau code du site.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('historique_codes', function (Blueprint $table) {
            $table->id();
            $table->string('objet_type', 20); // site, boutique
            $table->unsignedBigInteger('objet_id');
            $table->string('ancien_code', 30)->index();
            $table->string('nouveau_code', 30);
            $table->foreignId('auteur_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['objet_type', 'objet_id']);
        });
        AppendOnlyTable::protect('historique_codes');
    }

    public function down(): void
    {
        AppendOnlyTable::unprotect('historique_codes');
        Schema::dropIfExists('historique_codes');
    }
};
