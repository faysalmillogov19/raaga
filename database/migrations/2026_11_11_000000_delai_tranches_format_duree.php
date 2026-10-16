<?php

use App\Support\AppendOnlyTable;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Délai de paiement d'une tranche d'actions au format des autres délais : valeur + durée (obligatoire) et valeur en
 * secondes calculée. Conversion des tranches existantes (heures → valeur, HEURE, secondes), de leurs versions figées
 * et des avenants. Les versions figées sont en ajout seul : leur protection est levée le temps de cette seule
 * conversion, puis rétablie ; la conversion est tracée dans le journal d'audit (action système).
 */
return new class extends Migration
{
    private const ANCIEN = 'delai_paiement_heures';

    public function up(): void
    {
        $heure = (int) DB::table('durees')->where('code', 'HEURE')->value('id');

        Schema::table('tranches', function (Blueprint $table) {
            $table->unsignedInteger('delai_paiement_valeur')->nullable();
            $table->foreignId('delai_paiement_duree_id')->nullable()->constrained('durees', indexName: 'tranches_delai_duree_fk')->restrictOnDelete();
            $table->unsignedInteger('delai_paiement_secondes')->nullable();
        });
        DB::table('tranches')->whereNotNull(self::ANCIEN)->update([
            'delai_paiement_valeur' => DB::raw(self::ANCIEN), 'delai_paiement_duree_id' => $heure,
            'delai_paiement_secondes' => DB::raw(self::ANCIEN.' * 3600'),
        ]);
        Schema::table('tranches', fn (Blueprint $table) => $table->dropColumn(self::ANCIEN));

        $convertir = function (array $p) use ($heure): array {
            if (! array_key_exists(self::ANCIEN, $p)) {
                return $p;
            }
            $h = $p[self::ANCIEN];
            unset($p[self::ANCIEN]);

            return [...$p, 'delai_paiement_valeur' => $h === null ? null : (int) $h, 'delai_paiement_duree_id' => $h === null ? null : $heure,
                'delai_paiement_secondes' => $h === null ? null : (int) $h * 3_600];
        };
        $decoder = fn ($json) => is_string($json) ? (json_decode($json, true) ?? []) : (array) $json;

        // Versions figées : protection levée pour cette conversion seulement.
        $versions = DB::table('tranche_versions')->get(['id', 'parametres'])
            ->filter(fn ($v) => array_key_exists(self::ANCIEN, $decoder($v->parametres)));
        if ($versions->isNotEmpty()) {
            AppendOnlyTable::unprotect('tranche_versions');
            foreach ($versions as $v) {
                DB::table('tranche_versions')->where('id', $v->id)->update(['parametres' => json_encode($convertir($decoder($v->parametres)))]);
            }
            AppendOnlyTable::protect('tranche_versions');
        }

        // Avenants des tranches (valeurs avant / après).
        $avenants = DB::table('avenants')->where('avenantable_type', 'like', '%ranche%')->get(['id', 'ancienne_valeur', 'nouvelle_valeur'])
            ->filter(fn ($a) => array_key_exists(self::ANCIEN, $decoder($a->ancienne_valeur)) || array_key_exists(self::ANCIEN, $decoder($a->nouvelle_valeur)));
        foreach ($avenants as $a) {
            DB::table('avenants')->where('id', $a->id)->update([
                'ancienne_valeur' => json_encode($convertir($decoder($a->ancienne_valeur))),
                'nouvelle_valeur' => json_encode($convertir($decoder($a->nouvelle_valeur))),
            ]);
        }

        if ($versions->isNotEmpty() || $avenants->isNotEmpty()) {
            DB::table('audit_logs')->insert([
                'acteur_id' => null, 'action' => 'tranches.delai_converti', 'entite_type' => 'Tranche', 'entite_id' => null,
                'ancienne_valeur' => json_encode(['format' => self::ANCIEN]),
                'nouvelle_valeur' => json_encode(['format' => 'valeur + durée + secondes', 'versions' => $versions->pluck('id')->values(), 'avenants' => $avenants->pluck('id')->values()]),
                'created_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('tranches', fn (Blueprint $table) => $table->unsignedInteger(self::ANCIEN)->nullable());
        DB::table('tranches')->whereNotNull('delai_paiement_secondes')->update([self::ANCIEN => DB::raw('delai_paiement_secondes / 3600')]);
        Schema::table('tranches', function (Blueprint $table) {
            $table->dropForeign('tranches_delai_duree_fk');
            $table->dropColumn(['delai_paiement_valeur', 'delai_paiement_duree_id', 'delai_paiement_secondes']);
        });

        $revenir = function (array $p): array {
            if (! array_key_exists('delai_paiement_secondes', $p)) {
                return $p;
            }
            $s = $p['delai_paiement_secondes'];
            unset($p['delai_paiement_valeur'], $p['delai_paiement_duree_id'], $p['delai_paiement_secondes']);

            return [...$p, self::ANCIEN => $s === null ? null : intdiv((int) $s, 3_600)];
        };
        $decoder = fn ($json) => is_string($json) ? (json_decode($json, true) ?? []) : (array) $json;
        AppendOnlyTable::unprotect('tranche_versions');
        foreach (DB::table('tranche_versions')->get(['id', 'parametres']) as $v) {
            DB::table('tranche_versions')->where('id', $v->id)->update(['parametres' => json_encode($revenir($decoder($v->parametres)))]);
        }
        AppendOnlyTable::protect('tranche_versions');
        foreach (DB::table('avenants')->where('avenantable_type', 'like', '%ranche%')->get(['id', 'ancienne_valeur', 'nouvelle_valeur']) as $a) {
            DB::table('avenants')->where('id', $a->id)->update([
                'ancienne_valeur' => json_encode($revenir($decoder($a->ancienne_valeur))), 'nouvelle_valeur' => json_encode($revenir($decoder($a->nouvelle_valeur))),
            ]);
        }
    }
};
