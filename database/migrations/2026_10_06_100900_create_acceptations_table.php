<?php

use App\Support\AppendOnlyTable;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Preuves d'acceptation des conditions (case « aucun droit à une boutique », règlement…) — ajout seul.
     */
    public function up(): void
    {
        Schema::create('acceptations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('souscription_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('document', 40);
            // Pour le règlement : numéro de TrancheVersion dont le PDF a été accepté.
            $table->string('version_document', 40);
            $table->char('empreinte_document', 64)->nullable();
            $table->timestamp('accepte_le', 6);
            $table->string('ip', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['user_id', 'document']);
        });

        AppendOnlyTable::protect('acceptations');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        AppendOnlyTable::unprotect('acceptations');
        Schema::dropIfExists('acceptations');
    }
};
