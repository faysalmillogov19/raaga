<?php

use App\Support\AppendOnlyTable;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Paramètres de la plateforme : les informations publiques (identité, contacts, mentions légales,
     * réseaux, chiffres du projet, messages de service), une ligne par section, et l'historique de
     * chaque enregistrement (ajout seul).
     */
    public function up(): void
    {
        Schema::create('parametres_plateforme', function (Blueprint $table) {
            $table->id();
            $table->string('section', 40)->unique();
            $table->json('valeurs');
            $table->foreignId('modifie_par')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('parametres_plateforme_versions', function (Blueprint $table) {
            $table->id();
            $table->string('section', 40)->index();
            $table->json('ancienne_valeur')->nullable();
            $table->json('nouvelle_valeur');
            // Sans « nullOnDelete » : une table en ajout seul refuse toute mise à jour.
            $table->foreignId('auteur_id')->nullable()->constrained('users');
            $table->unsignedBigInteger('restauree_depuis')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });
        AppendOnlyTable::protect('parametres_plateforme_versions');
    }

    public function down(): void
    {
        AppendOnlyTable::unprotect('parametres_plateforme_versions');
        Schema::dropIfExists('parametres_plateforme_versions');
        Schema::dropIfExists('parametres_plateforme');
    }
};
