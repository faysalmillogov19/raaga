<?php

use App\Support\AppendOnlyTable;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Grille tarifaire des boutiques, une par site (lignes par niveau et type : loyer au m² et par mois, mois de caution,
 * mois d'avance), saisie par le RÉGULATEUR et confirmée par le VALIDATEUR, versions figées. Les lots de campagne
 * deviennent homogènes (site × type × niveau × surface) : leurs montants sont figés à l'ouverture, puis copiés dans
 * chaque candidature. Caution et avance restent deux montants distincts.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('grilles_tarifaires', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->unique()->constrained()->restrictOnDelete();
            $table->string('statut', 20)->index();
            $table->unsignedInteger('version')->default(0);
            $table->foreignId('cree_par')->constrained('users')->restrictOnDelete();
            $table->foreignId('soumis_par')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('soumis_le')->nullable();
            $table->foreignId('valide_par')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('valide_le')->nullable();
            $table->text('motif_rejet')->nullable();
            $table->timestamps();
        });

        Schema::create('grille_tarifaire_lignes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('grille_tarifaire_id')->constrained('grilles_tarifaires')->cascadeOnDelete();
            $table->string('niveau', 2);
            $table->foreignId('type_boutique_id')->constrained('types_boutiques')->restrictOnDelete();
            $table->unsignedInteger('loyer_m2');
            $table->unsignedSmallInteger('mois_caution');
            $table->unsignedSmallInteger('mois_avance');
            $table->timestamps();

            $table->unique(['grille_tarifaire_id', 'niveau', 'type_boutique_id'], 'grille_ligne_unique');
        });

        Schema::create('grille_tarifaire_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('grille_tarifaire_id')->constrained('grilles_tarifaires')->restrictOnDelete();
            $table->unsignedInteger('numero');
            $table->json('parametres');
            $table->foreignId('auteur_id')->constrained('users')->restrictOnDelete();
            $table->string('motif')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['grille_tarifaire_id', 'numero']);
        });
        AppendOnlyTable::protect('grille_tarifaire_versions');

        Schema::table('categories_boutiques', function (Blueprint $table) {
            $table->string('niveau', 2)->nullable()->after('type_boutique_id');
            $table->decimal('surface', 7, 2)->nullable()->after('niveau');
            // Figés à l'ouverture de la campagne, depuis la grille confirmée (version notée).
            $table->unsignedInteger('grille_version')->nullable()->after('caution');
            $table->unsignedInteger('loyer_m2')->nullable()->after('grille_version');
            $table->unsignedInteger('loyer_mensuel')->nullable()->after('loyer_m2');
            $table->unsignedSmallInteger('mois_caution')->nullable()->after('loyer_mensuel');
            $table->unsignedSmallInteger('mois_avance')->nullable()->after('mois_caution');
            $table->unsignedBigInteger('avance')->default(0)->after('mois_avance');
        });
        Schema::table('categories_boutiques', function (Blueprint $table) {
            // Caution calculée à l'ouverture : vide tant que la campagne n'est pas ouverte.
            $table->unsignedBigInteger('caution')->nullable()->change();
        });

        Schema::table('candidatures_boutiques', function (Blueprint $table) {
            // Montants figés à la candidature (copiés du lot).
            $table->unsignedBigInteger('avance')->default(0)->after('caution');
            $table->unsignedInteger('loyer_mensuel')->nullable()->after('avance');
            $table->unsignedSmallInteger('mois_caution')->nullable()->after('loyer_mensuel');
            $table->unsignedSmallInteger('mois_avance')->nullable()->after('mois_caution');
        });
    }

    public function down(): void
    {
        Schema::table('candidatures_boutiques', function (Blueprint $table) {
            $table->dropColumn(['avance', 'loyer_mensuel', 'mois_caution', 'mois_avance']);
        });
        Schema::table('categories_boutiques', function (Blueprint $table) {
            $table->dropColumn(['niveau', 'surface', 'grille_version', 'loyer_m2', 'loyer_mensuel', 'mois_caution', 'mois_avance', 'avance']);
        });
        AppendOnlyTable::unprotect('grille_tarifaire_versions');
        Schema::dropIfExists('grille_tarifaire_versions');
        Schema::dropIfExists('grille_tarifaire_lignes');
        Schema::dropIfExists('grilles_tarifaires');
    }
};
