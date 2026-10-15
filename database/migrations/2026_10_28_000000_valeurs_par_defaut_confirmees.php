<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Valeurs « par défaut » confirmées d'office :
 *  - colonne modification_demandee : une modification d'un élément par défaut attend la décision du VALIDATEUR ;
 *  - rattrapage : les éléments par défaut encore « à valider » passent « validés », sans décision de validation, et
 *    reçoivent une version au nom du système ; les unités par défaut sans version reçoivent leur version 1.
 * Les tables de versions et de décisions (ajout seul) ne reçoivent que des insertions : rien n'y est modifié ni supprimé.
 */
return new class extends Migration
{
    private const TABLES = ['types_boutiques', 'niveaux', 'zones', 'unites'];

    /** Table => type d'objet dans referentiel_versions, et champs figés dans la version. */
    private const REFERENTIELS = [
        'niveaux' => ['niveau', ['code', 'libelle', 'rang', 'actif', 'statut']],
        'zones' => ['zone', ['code', 'libelle', 'description', 'ordre', 'actif', 'statut']],
        'unites' => ['unite', ['code', 'libelle', 'description', 'actif', 'statut']],
    ];

    public function up(): void
    {
        foreach (self::TABLES as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->json('modification_demandee')->nullable();
            });
        }

        $this->confirmerParDefaut();
    }

    /** Rattrapage idempotent : rien à faire quand tout élément par défaut est déjà validé avec une version. */
    public function confirmerParDefaut(): void
    {
        $motif = __('admin.types.motifs.defaut');
        $maintenant = now();

        DB::transaction(function () use ($motif, $maintenant) {
            foreach (DB::table('types_boutiques')->where('par_defaut', true)->where(fn ($q) => $q->where('statut', 'A_VALIDER')->orWhere('version', 0))->get() as $type) {
                DB::table('types_boutiques')->where('id', $type->id)->update(['statut' => 'VALIDE', 'valide_le' => $type->valide_le ?? $maintenant]);
                $numero = (int) DB::table('type_boutique_versions')->where('type_boutique_id', $type->id)->max('numero') + 1;
                DB::table('type_boutique_versions')->insert([
                    'type_boutique_id' => $type->id, 'numero' => $numero, 'auteur_id' => null, 'motif' => $motif, 'created_at' => $maintenant,
                    'parametres' => json_encode(['code' => $type->code, 'libelle' => $type->libelle, 'description' => $type->description,
                        'attribuable' => (bool) $type->attribuable, 'actif' => (bool) $type->actif, 'ordre' => $type->ordre, 'statut' => 'VALIDE']),
                ]);
                DB::table('types_boutiques')->where('id', $type->id)->update(['version' => $numero]);
            }

            foreach (self::REFERENTIELS as $table => [$objet, $champs]) {
                foreach (DB::table($table)->where('par_defaut', true)->where(fn ($q) => $q->where('statut', 'A_VALIDER')->orWhere('version', 0))->get() as $element) {
                    DB::table($table)->where('id', $element->id)->update(['statut' => 'VALIDE', 'valide_le' => $element->valide_le ?? $maintenant]);
                    $numero = (int) DB::table('referentiel_versions')->where('objet_type', $objet)->where('objet_id', $element->id)->max('numero') + 1;
                    $parametres = collect($champs)->mapWithKeys(fn ($c) => [$c => $c === 'actif' ? (bool) $element->actif : $element->{$c}])->all();
                    DB::table('referentiel_versions')->insert([
                        'objet_type' => $objet, 'objet_id' => $element->id, 'numero' => $numero, 'auteur_id' => null, 'motif' => $motif,
                        'created_at' => $maintenant, 'parametres' => json_encode([...$parametres, 'statut' => 'VALIDE']),
                    ]);
                    DB::table($table)->where('id', $element->id)->update(['version' => $numero]);
                }
            }
        });
    }

    public function down(): void
    {
        foreach (self::TABLES as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->dropColumn('modification_demandee');
            });
        }
    }
};
