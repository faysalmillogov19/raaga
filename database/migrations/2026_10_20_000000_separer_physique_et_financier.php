<?php

use App\Support\AppendOnlyTable;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Le physique et le financier sont séparés : le GESTIONNAIRE DES SITES ET BOUTIQUES ne saisit ni ne voit aucun montant.
 * - L'unité du loyer quitte le type de boutique pour chaque ligne de grille tarifaire (RÉGULATEUR → VALIDATEUR) ;
 *   l'unité actuelle de chaque type est reportée sur ses lignes de grille.
 * - Coût total, part attendue des occupants et source du complément quittent la fiche du site pour ses
 *   « données financières » (site_finances), saisies par le RÉGULATEUR et confirmées par le VALIDATEUR, versions figées.
 *   Les valeurs existantes sont reprises en brouillon, à soumettre.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('grille_tarifaire_lignes', function (Blueprint $table) {
            $table->string('unite_loyer', 10)->default('m2')->after('type_boutique_id');
        });
        foreach (DB::table('types_boutiques')->whereNotNull('unite_loyer')->get(['id', 'unite_loyer']) as $type) {
            DB::table('grille_tarifaire_lignes')->where('type_boutique_id', $type->id)->update(['unite_loyer' => $type->unite_loyer]);
        }
        Schema::table('types_boutiques', fn (Blueprint $table) => $table->dropColumn('unite_loyer'));

        Schema::create('site_finances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->unique()->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('cout_total')->nullable();
            $table->unsignedBigInteger('part_occupants')->nullable();
            $table->string('source_complement', 200)->nullable();
            $table->string('statut', 20)->default('BROUILLON')->index();
            $table->unsignedInteger('version')->default(0);
            $table->foreignId('soumis_par')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('soumis_le')->nullable();
            $table->foreignId('valide_par')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('valide_le')->nullable();
            $table->text('motif_rejet')->nullable();
            $table->timestamps();
        });

        Schema::create('site_finance_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_finance_id')->constrained('site_finances')->restrictOnDelete();
            $table->unsignedInteger('numero');
            $table->json('parametres');
            $table->foreignId('auteur_id')->constrained('users')->restrictOnDelete();
            $table->string('motif')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['site_finance_id', 'numero']);
        });
        AppendOnlyTable::protect('site_finance_versions');

        $maintenant = now();
        foreach (DB::table('sites')->where(fn ($q) => $q->whereNotNull('cout_total')->orWhereNotNull('part_occupants')->orWhereNotNull('source_complement'))->get() as $site) {
            DB::table('site_finances')->insert([
                'site_id' => $site->id, 'cout_total' => $site->cout_total, 'part_occupants' => $site->part_occupants,
                'source_complement' => $site->source_complement, 'statut' => 'BROUILLON', 'created_at' => $maintenant, 'updated_at' => $maintenant,
            ]);
        }
        Schema::table('sites', fn (Blueprint $table) => $table->dropColumn(['cout_total', 'part_occupants', 'source_complement']));
    }

    public function down(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->unsignedBigInteger('cout_total')->nullable();
            $table->unsignedBigInteger('part_occupants')->nullable();
            $table->string('source_complement', 200)->nullable();
        });
        foreach (DB::table('site_finances')->get() as $f) {
            DB::table('sites')->where('id', $f->site_id)->update(['cout_total' => $f->cout_total, 'part_occupants' => $f->part_occupants, 'source_complement' => $f->source_complement]);
        }
        AppendOnlyTable::unprotect('site_finance_versions');
        Schema::dropIfExists('site_finance_versions');
        Schema::dropIfExists('site_finances');
        Schema::table('types_boutiques', fn (Blueprint $table) => $table->string('unite_loyer', 10)->default('m2'));
        Schema::table('grille_tarifaire_lignes', fn (Blueprint $table) => $table->dropColumn('unite_loyer'));
    }
};
