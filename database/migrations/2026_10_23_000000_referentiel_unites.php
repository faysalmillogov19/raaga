<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Référentiel des unités de loyer (lues par la grille tarifaire) : RÉGULATEUR saisit, VALIDATEUR confirme ;
     * code unique et jamais modifié. Les trois unités de calcul existantes sont créées validées et « par défaut ».
     * Préférences du menu latéral du back-office (menus dépliants ouverts ou fermés), par utilisateur.
     */
    public function up(): void
    {
        Schema::create('unites', function (Blueprint $table) {
            $table->id();
            $table->string('code', 10)->unique();
            $table->string('libelle', 100);
            $table->string('description', 300)->nullable();
            $table->boolean('actif')->default(true);
            $table->boolean('par_defaut')->default(false);
            $table->string('statut', 12)->default('VALIDE')->index();
            $table->foreignId('demande_par')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('valide_par')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('valide_le')->nullable();
            $table->text('motif_rejet')->nullable();
            $table->unsignedInteger('version')->default(0);
            $table->timestamps();
        });

        $maintenant = now();
        DB::table('unites')->insert(array_map(fn (array $u) => [
            'code' => $u[0], 'libelle' => $u[1], 'description' => null, 'actif' => true, 'par_defaut' => true, 'statut' => 'VALIDE',
            'created_at' => $maintenant, 'updated_at' => $maintenant,
        ], [['M2', 'Au mètre carré par mois'], ['UNITE', 'À l’unité (étal, poste) par mois'], ['REDEVANCE', 'Redevance d’usage']]));

        Schema::table('users', function (Blueprint $table) {
            $table->json('menus_backoffice')->nullable()->after('theme_backoffice');
        });
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn('menus_backoffice'));
        Schema::dropIfExists('unites');
    }
};
