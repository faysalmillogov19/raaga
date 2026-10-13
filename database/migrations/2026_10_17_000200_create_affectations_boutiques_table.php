<?php

use App\Support\AppendOnlyTable;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Premier tirage : liste des retenus et des candidatures écartées (plafond par personne atteint).
 * Second tirage : affectation d'une boutique précise à chaque retenu (graine publiée, ordre des boutiques). Ajout seul.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tirages_boutiques', function (Blueprint $table) {
            $table->json('retenus')->nullable()->after('ordre');
            $table->json('ecartes')->nullable()->after('retenus');
        });

        Schema::create('affectations_boutiques', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tirage_boutiques_id')->unique()->constrained('tirages_boutiques')->restrictOnDelete();
            $table->char('graine', 64);
            // Boutiques dans l'ordre du second tirage, et {référence de candidature: numéro de boutique}.
            $table->json('ordre_boutiques');
            $table->json('affectations');
            $table->foreignId('tire_par')->constrained('users')->restrictOnDelete();
            $table->timestamp('created_at')->useCurrent();
        });
        AppendOnlyTable::protect('affectations_boutiques');
    }

    public function down(): void
    {
        AppendOnlyTable::unprotect('affectations_boutiques');
        Schema::dropIfExists('affectations_boutiques');
        Schema::table('tirages_boutiques', fn (Blueprint $table) => $table->dropColumn(['retenus', 'ecartes']));
    }
};
