<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Durées (SECONDE, MINUTE, HEURE, JOUR) et délais enregistrés en valeur + durée, avec leur valeur en secondes :
 * paramètres des paiements (caution, complément, nouveau délai de l'avance) et ancien délai propre à une campagne
 * (converti, gardé). Date limite de l'avance figée sur chaque réservation passée « réservée ».
 */
return new class extends Migration
{
    /** Délais des paramètres des paiements. */
    private const DELAIS = ['caution', 'complement', 'avance'];

    public function up(): void
    {
        Schema::create('durees', function (Blueprint $table) {
            $table->id();
            $table->string('code', 10)->unique();
            $table->string('libelle', 30);
            $table->string('libelle_pluriel', 30);
            $table->unsignedInteger('secondes');
            $table->unsignedSmallInteger('ordre')->default(0);
            $table->timestamps();
        });
        // Les quatre durées existent dès la migration (conversion ci-dessous) ; le seeder les garantit ensuite.
        foreach ([['SECONDE', 'seconde', 'secondes', 1, 1], ['MINUTE', 'minute', 'minutes', 60, 2], ['HEURE', 'heure', 'heures', 3_600, 3], ['JOUR', 'jour', 'jours', 86_400, 4]] as [$code, $libelle, $pluriel, $secondes, $ordre]) {
            DB::table('durees')->insert(['code' => $code, 'libelle' => $libelle, 'libelle_pluriel' => $pluriel, 'secondes' => $secondes, 'ordre' => $ordre,
                'created_at' => now(), 'updated_at' => now()]);
        }
        $heure = DB::table('durees')->where('code', 'HEURE')->value('id');

        Schema::table('parametres_paiements', function (Blueprint $table) {
            foreach (self::DELAIS as $delai) {
                $table->unsignedInteger('delai_'.$delai.'_valeur')->nullable();
                $table->foreignId('delai_'.$delai.'_duree_id')->nullable()->constrained('durees', indexName: 'pp_'.$delai.'_duree_fk')->restrictOnDelete();
                $table->unsignedInteger('delai_'.$delai.'_secondes')->nullable();
            }
        });
        DB::table('parametres_paiements')->whereNotNull('delai_complement_heures')->update([
            'delai_complement_valeur' => DB::raw('delai_complement_heures'), 'delai_complement_duree_id' => $heure,
            'delai_complement_secondes' => DB::raw('delai_complement_heures * 3600'),
        ]);
        Schema::table('parametres_paiements', fn (Blueprint $table) => $table->dropColumn('delai_complement_heures'));

        // Délai de caution propre à une campagne : gardé, converti au même format.
        Schema::table('campagnes_boutiques', function (Blueprint $table) {
            $table->unsignedInteger('delai_paiement_valeur')->nullable();
            $table->foreignId('delai_paiement_duree_id')->nullable()->constrained('durees', indexName: 'cb_delai_duree_fk')->restrictOnDelete();
            $table->unsignedInteger('delai_paiement_secondes')->nullable();
        });
        DB::table('campagnes_boutiques')->whereNotNull('delai_paiement_heures')->update([
            'delai_paiement_valeur' => DB::raw('delai_paiement_heures'), 'delai_paiement_duree_id' => $heure,
            'delai_paiement_secondes' => DB::raw('delai_paiement_heures * 3600'),
        ]);
        Schema::table('campagnes_boutiques', fn (Blueprint $table) => $table->dropColumn('delai_paiement_heures'));

        // Date limite de paiement de l'avance, figée au passage en « réservée » (30 jours par défaut).
        Schema::table('reservations_boutiques', fn (Blueprint $table) => $table->timestamp('avance_due_le')->nullable());
        foreach (DB::table('reservations_boutiques')->where('statut', 'RESERVEE')->get(['id', 'reservee_le', 'payee_le']) as $r) {
            $depart = $r->reservee_le ?? $r->payee_le;
            if ($depart) {
                DB::table('reservations_boutiques')->where('id', $r->id)->update(['avance_due_le' => Carbon::parse($depart)->addDays(30)]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('reservations_boutiques', fn (Blueprint $table) => $table->dropColumn('avance_due_le'));
        Schema::table('campagnes_boutiques', fn (Blueprint $table) => $table->unsignedInteger('delai_paiement_heures')->nullable());
        DB::table('campagnes_boutiques')->whereNotNull('delai_paiement_secondes')->update(['delai_paiement_heures' => DB::raw('delai_paiement_secondes / 3600')]);
        Schema::table('campagnes_boutiques', function (Blueprint $table) {
            $table->dropForeign('cb_delai_duree_fk');
            $table->dropColumn(['delai_paiement_valeur', 'delai_paiement_duree_id', 'delai_paiement_secondes']);
        });
        Schema::table('parametres_paiements', fn (Blueprint $table) => $table->unsignedInteger('delai_complement_heures')->nullable());
        DB::table('parametres_paiements')->whereNotNull('delai_complement_secondes')->update(['delai_complement_heures' => DB::raw('delai_complement_secondes / 3600')]);
        Schema::table('parametres_paiements', function (Blueprint $table) {
            foreach (self::DELAIS as $delai) {
                $table->dropForeign('pp_'.$delai.'_duree_fk');
                $table->dropColumn(['delai_'.$delai.'_valeur', 'delai_'.$delai.'_duree_id', 'delai_'.$delai.'_secondes']);
            }
        });
        Schema::dropIfExists('durees');
    }
};
