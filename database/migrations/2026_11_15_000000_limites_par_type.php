<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Limite de réservation par type d'emplacement (nombre maximum par personne pour chaque type coché), à la place de
 * la limite unique de la campagne. Chaque type d'une campagne existante reçoit l'ancienne limite globale ; chaque
 * conversion est tracée dans le journal d'audit (action système « campagne_boutiques.limites_converties »).
 * Les versions figées gardent l'ancienne limite (historique).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('campagnes_boutiques', fn (Blueprint $table) => $table->json('limites')->nullable());

        foreach (DB::table('campagnes_boutiques')->get(['id', 'nom', 'types', 'max_boutiques_par_personne']) as $c) {
            $types = json_decode((string) $c->types, true) ?: [];
            $max = max(1, (int) $c->max_boutiques_par_personne);
            $limites = (object) collect($types)->mapWithKeys(fn ($id) => [(string) (int) $id => $max])->all();
            DB::table('campagnes_boutiques')->where('id', $c->id)->update(['limites' => json_encode($limites)]);
            DB::table('audit_logs')->insert([
                'acteur_id' => null, 'action' => 'campagne_boutiques.limites_converties', 'entite_type' => 'CampagneBoutiques', 'entite_id' => (string) $c->id,
                'ancienne_valeur' => json_encode(['max_boutiques_par_personne' => $max]),
                'nouvelle_valeur' => json_encode(['nom' => $c->nom, 'limites' => $limites], JSON_UNESCAPED_UNICODE), 'created_at' => now(),
            ]);
        }

        Schema::table('campagnes_boutiques', fn (Blueprint $table) => $table->dropColumn('max_boutiques_par_personne'));
    }

    public function down(): void
    {
        Schema::table('campagnes_boutiques', fn (Blueprint $table) => $table->unsignedInteger('max_boutiques_par_personne')->default(1));
        foreach (DB::table('campagnes_boutiques')->get(['id', 'limites']) as $c) {
            $limites = json_decode((string) $c->limites, true) ?: [];
            DB::table('campagnes_boutiques')->where('id', $c->id)->update(['max_boutiques_par_personne' => $limites ? max($limites) : 1]);
        }
        Schema::table('campagnes_boutiques', fn (Blueprint $table) => $table->dropColumn('limites'));
    }
};
