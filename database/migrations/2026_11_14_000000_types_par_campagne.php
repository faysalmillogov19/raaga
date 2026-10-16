<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Types proposés par campagne (en plus des sites) et description des types pour le citoyen. Les campagnes existantes
 * reçoivent tous les types attribuables de leurs sites (jamais la chambre froide mutualisée CF). Les descriptions par
 * défaut sont posées par TypesBoutiqueSeeder, confirmées d'office.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('types_boutiques', fn (Blueprint $table) => $table->string('description_citoyen', 300)->nullable());
        Schema::table('campagnes_boutiques', fn (Blueprint $table) => $table->json('types')->nullable());

        foreach (DB::table('campagnes_boutiques')->get(['id', 'sites']) as $campagne) {
            $sites = json_decode((string) $campagne->sites, true) ?: [];
            $types = DB::table('boutiques')->join('types_boutiques', 'types_boutiques.id', '=', 'boutiques.type_boutique_id')
                ->whereIn('boutiques.site_id', $sites)->where('types_boutiques.attribuable', true)
                ->where(fn ($q) => $q->whereNull('types_boutiques.code')->orWhere('types_boutiques.code', '!=', 'CF'))
                ->distinct()->orderBy('types_boutiques.id')->pluck('types_boutiques.id')->map(fn ($id) => (int) $id)->values()->all();
            DB::table('campagnes_boutiques')->where('id', $campagne->id)->update(['types' => json_encode($types)]);
        }
    }

    public function down(): void
    {
        Schema::table('campagnes_boutiques', fn (Blueprint $table) => $table->dropColumn('types'));
        Schema::table('types_boutiques', fn (Blueprint $table) => $table->dropColumn('description_citoyen'));
    }
};
