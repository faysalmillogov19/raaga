<?php

use App\Support\AppendOnlyTable;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Niveaux et zones deviennent des référentiels gérés (comme les types de boutiques) : table « niveaux » (à la place
 * de l'enum NiveauBoutique) et table « zones » (ex-« zones_sites »), versions communes « referentiel_versions ».
 * Les codes déjà enregistrés sont repris tels quels : les boutiques, capacités, lignes de grille et lots référencent
 * désormais le code du niveau (clé étrangère) ; aucun code boutique ne change.
 */
return new class extends Migration
{
    /** Niveaux de l'ancien enum : [code, libellé, rang]. */
    private const NIVEAUX = [['SS', 'Sous-sol', -1], ['RC', 'Rez-de-chaussée', 0], ['R1', 'R+1', 1], ['R2', 'R+2', 2], ['R3', 'R+3', 3]];

    /** Zones par défaut (déjà créées par le seeder) : marquées « par défaut » si elles existent. */
    private const ZONES = ['A', 'B', 'MAG', 'ETAL', 'BP', 'CF', 'REST'];

    /** Tables qui portent un code de niveau. */
    private const AVEC_NIVEAU = ['boutiques', 'capacites_sites', 'grille_tarifaire_lignes', 'categories_boutiques'];

    public function up(): void
    {
        Schema::create('niveaux', function (Blueprint $table) {
            $table->id();
            $table->string('code', 3)->unique();
            $table->string('libelle', 80);
            $table->smallInteger('rang')->default(0)->index();
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

        // Reprise : les niveaux de l'ancien enum, puis tout autre code déjà enregistré (rien n'est perdu).
        $maintenant = now();
        foreach (self::NIVEAUX as [$code, $libelle, $rang]) {
            DB::table('niveaux')->insert(['code' => $code, 'libelle' => $libelle, 'rang' => $rang, 'par_defaut' => true, 'created_at' => $maintenant, 'updated_at' => $maintenant]);
        }
        $codes = collect(self::AVEC_NIVEAU)->flatMap(fn (string $t) => DB::table($t)->whereNotNull('niveau')->distinct()->pluck('niveau'))
            ->unique()->diff(array_column(self::NIVEAUX, 0));
        foreach ($codes as $code) {
            DB::table('niveaux')->insert(['code' => $code, 'libelle' => $code, 'rang' => 99, 'created_at' => $maintenant, 'updated_at' => $maintenant]);
        }

        foreach (self::AVEC_NIVEAU as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->string('niveau', 3)->nullable()->change();
                $t->foreign('niveau')->references('code')->on('niveaux')->restrictOnDelete();
            });
        }

        Schema::rename('zones_sites', 'zones');
        Schema::table('zones', function (Blueprint $table) {
            $table->string('description', 300)->nullable()->after('libelle');
            $table->unsignedSmallInteger('ordre')->default(0)->after('description');
            $table->unsignedInteger('version')->default(0)->after('motif_rejet');
        });
        DB::table('zones')->update(['ordre' => DB::raw('id')]);
        DB::table('zones')->whereIn('code', self::ZONES)->update(['par_defaut' => true]);
        Schema::table('capacites_sites', function (Blueprint $table) {
            $table->foreign('zone')->references('code')->on('zones')->restrictOnDelete();
        });

        Schema::create('referentiel_versions', function (Blueprint $table) {
            $table->id();
            $table->string('objet_type', 20); // niveau, zone
            // Sans clé étrangère : l'historique d'un élément supprimé (jamais utilisé) est conservé.
            $table->unsignedBigInteger('objet_id');
            $table->unsignedInteger('numero');
            $table->json('parametres');
            $table->foreignId('auteur_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('motif')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['objet_type', 'objet_id', 'numero']);
        });
        AppendOnlyTable::protect('referentiel_versions');
    }

    public function down(): void
    {
        AppendOnlyTable::unprotect('referentiel_versions');
        Schema::dropIfExists('referentiel_versions');
        Schema::table('capacites_sites', fn (Blueprint $t) => $t->dropForeign(['zone']));
        Schema::table('zones', fn (Blueprint $t) => $t->dropColumn(['description', 'ordre', 'version']));
        Schema::rename('zones', 'zones_sites');
        foreach (self::AVEC_NIVEAU as $table) {
            Schema::table($table, fn (Blueprint $t) => $t->dropForeign(['niveau']));
        }
        Schema::dropIfExists('niveaux');
    }
};
