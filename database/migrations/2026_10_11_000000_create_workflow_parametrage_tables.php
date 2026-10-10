<?php

use App\Support\AppendOnlyTable;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Workflow commun des paramétrages (tranches, distributions de dividendes) :
     * - décisions de confirmation / rejet, en ajout seul ;
     * - avenants génériques (remplacent tranche_avenants) ;
     * - statuts des tranches renommés : EN_VALIDATION → SOUMISE, VALIDEE → CONFIRMEE.
     */
    public function up(): void
    {
        Schema::create('parametrage_decisions', function (Blueprint $table) {
            $table->id();
            $table->string('objet_type', 40);
            $table->unsignedBigInteger('objet_id');
            $table->unsignedInteger('version');
            $table->string('decision', 20);
            $table->text('motif')->nullable();
            $table->foreignId('decideur_id')->constrained('users')->restrictOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['objet_type', 'objet_id']);
        });
        AppendOnlyTable::protect('parametrage_decisions');

        Schema::dropIfExists('tranche_avenants');

        Schema::create('avenants', function (Blueprint $table) {
            $table->id();
            $table->morphs('avenantable');
            // Paramètres modifiés : {champ: valeur} avant / après.
            $table->json('ancienne_valeur');
            $table->json('nouvelle_valeur');
            $table->text('motif');
            $table->string('statut', 20)->index();
            $table->foreignId('saisi_par')->constrained('users')->restrictOnDelete();
            $table->foreignId('decide_par')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('decide_le')->nullable();
            $table->text('motif_decision')->nullable();
            $table->timestamps();
        });

        DB::table('tranches')->where('statut', 'EN_VALIDATION')->update(['statut' => 'SOUMISE']);
        DB::table('tranches')->where('statut', 'VALIDEE')->update(['statut' => 'CONFIRMEE']);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('tranches')->where('statut', 'SOUMISE')->update(['statut' => 'EN_VALIDATION']);
        DB::table('tranches')->where('statut', 'CONFIRMEE')->update(['statut' => 'VALIDEE']);

        Schema::dropIfExists('avenants');

        Schema::create('tranche_avenants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tranche_id')->constrained()->restrictOnDelete();
            $table->string('champ', 40)->default('date_cloture');
            $table->json('ancienne_valeur');
            $table->json('nouvelle_valeur');
            $table->text('motif');
            $table->string('statut', 20)->default('EN_ATTENTE')->index();
            $table->foreignId('saisi_par')->constrained('users')->restrictOnDelete();
            $table->foreignId('decide_par')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('decide_le')->nullable();
            $table->text('motif_decision')->nullable();
            $table->timestamps();
        });

        AppendOnlyTable::unprotect('parametrage_decisions');
        Schema::dropIfExists('parametrage_decisions');
    }
};
