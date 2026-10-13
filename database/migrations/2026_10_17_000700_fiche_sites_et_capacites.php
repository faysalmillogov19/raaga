<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Fiche complète des sites (localisation, maîtrise d'ouvrage, phase, financement, plans), capacités prévues par site
 * (niveau, zone, type), référentiel des zones (rempli par ReferentielsSitesSeeder) et types non attribuables.
 * Complément à financer et part des occupants en % se calculent : ils ne sont jamais stockés.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->string('region', 60)->nullable()->change();
            $table->string('commune', 80)->nullable()->change();
            $table->string('adresse', 300)->nullable()->change();
            $table->string('ville', 80)->nullable()->after('nom');
            $table->string('arrondissement', 40)->nullable()->after('ville');
            $table->decimal('latitude', 10, 7)->nullable()->after('adresse');
            $table->decimal('longitude', 10, 7)->nullable()->after('latitude');
            $table->string('maitre_ouvrage', 200)->nullable()->after('longitude');
            $table->string('maitre_oeuvre', 200)->nullable()->after('maitre_ouvrage');
            $table->string('phase', 20)->nullable()->after('maitre_oeuvre');
            $table->date('livraison_prevue')->nullable()->after('phase');
            $table->unsignedBigInteger('cout_total')->nullable()->after('livraison_prevue');
            $table->unsignedBigInteger('part_occupants')->nullable()->after('cout_total');
            $table->string('source_complement', 200)->nullable()->after('part_occupants');
            $table->string('reference_plans', 200)->nullable()->after('source_complement');
            $table->string('version_plans', 60)->nullable()->after('reference_plans');
            $table->string('source_donnees', 300)->nullable()->after('version_plans');
        });
        // Sites déjà saisis : la commune devient la ville.
        DB::table('sites')->whereNull('ville')->update(['ville' => DB::raw('commune')]);

        Schema::create('zones_sites', function (Blueprint $table) {
            $table->id();
            $table->string('code', 6)->unique();
            $table->string('libelle', 100);
            $table->timestamps();
        });

        Schema::table('types_boutiques', function (Blueprint $table) {
            // Une chambre froide mutualisée, par exemple, ne se tire pas au sort.
            $table->boolean('attribuable')->default(true)->after('description');
        });

        Schema::create('capacites_sites', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->string('niveau', 2)->nullable();
            $table->string('zone', 6)->nullable();
            $table->foreignId('type_boutique_id')->constrained('types_boutiques')->restrictOnDelete();
            $table->unsignedInteger('nombre_prevu')->nullable();
            $table->decimal('surface_unitaire', 9, 2)->nullable();
            $table->decimal('surface_totale', 11, 2)->nullable();
            $table->string('source', 200)->nullable();
            $table->string('observations', 500)->nullable();
            $table->timestamps();

            $table->index(['site_id', 'type_boutique_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('capacites_sites');
        Schema::table('types_boutiques', fn (Blueprint $table) => $table->dropColumn('attribuable'));
        Schema::dropIfExists('zones_sites');
        Schema::table('sites', function (Blueprint $table) {
            $table->dropColumn(['ville', 'arrondissement', 'latitude', 'longitude', 'maitre_ouvrage', 'maitre_oeuvre', 'phase', 'livraison_prevue',
                'cout_total', 'part_occupants', 'source_complement', 'reference_plans', 'version_plans', 'source_donnees']);
        });
    }
};
