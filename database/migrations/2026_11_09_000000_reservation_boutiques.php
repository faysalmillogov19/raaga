<?php

use App\Support\AppendOnlyTable;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Boutiques réservées au premier arrivé, premier servi (fin du tirage au sort) :
 *  - les candidatures deviennent des réservations d'une boutique précise (table et colonnes renommées ; les données
 *    de l'ancien tirage, tirages_boutiques et affectations_boutiques, restent en lecture seule) ;
 *  - une seule réservation active par boutique, garantie en base (index unique partiel PostgreSQL ; colonne générée
 *    sous MySQL/MariaDB, base locale) ;
 *  - pièce d'identité de la réservation (type et numéro) pour la limite par personne ;
 *  - sites concernés par la campagne ;
 *  - historique des statuts des boutiques (ajout seul).
 */
return new class extends Migration
{
    private const ACTIFS = "'PRE_RESERVEE','RESERVEE','ATTRIBUEE'";

    public function up(): void
    {
        $pgsql = DB::getDriverName() === 'pgsql';

        DB::statement('ALTER TABLE paiements DROP CONSTRAINT paiements_compte_destination_coherent');
        Schema::rename('candidatures_boutiques', 'reservations_boutiques');
        foreach (['paiements', 'relances', 'remboursements', 'acceptations'] as $table) {
            Schema::table($table, fn (Blueprint $t) => $t->renameColumn('candidature_boutique_id', 'reservation_boutique_id'));
        }
        DB::statement("ALTER TABLE paiements ADD CONSTRAINT paiements_compte_destination_coherent CHECK (
            (souscription_id IS NOT NULL AND reservation_boutique_id IS NULL AND compte_destination = 'INVESTISSEMENT')
            OR (reservation_boutique_id IS NOT NULL AND souscription_id IS NULL AND compte_destination = 'LOCATION'))");

        // Anciennes candidatures du tirage : une caution jamais payée ne peut plus aboutir (expirée) ; les autres
        // statuts (PAYEE, RETENUE, NON_RETENUE) restent tels quels, en historique.
        DB::table('reservations_boutiques')->where('statut', 'RESERVEE')->update(['statut' => 'EXPIREE']);

        Schema::table('reservations_boutiques', function (Blueprint $table) {
            $table->foreignId('categorie_boutique_id')->nullable()->change();
            $table->string('type_piece', 20)->nullable();
            $table->string('numero_piece', 40)->nullable();
            $table->timestamp('reservee_le')->nullable();
            $table->timestamp('attribuee_le')->nullable();
            $table->string('reference_avance', 100)->nullable();
            $table->date('contrat_signe_le')->nullable();
            $table->index(['campagne_boutiques_id', 'numero_piece'], 'reservations_piece');
        });
        // Une seule réservation active (pré-réservée, réservée ou attribuée) par boutique.
        if ($pgsql) {
            DB::statement('CREATE UNIQUE INDEX reservations_boutique_active ON reservations_boutiques (boutique_id) WHERE statut IN ('.self::ACTIFS.')');
        } else {
            DB::statement('ALTER TABLE reservations_boutiques ADD COLUMN boutique_active BIGINT UNSIGNED AS (CASE WHEN statut IN ('.self::ACTIFS.') THEN boutique_id END) STORED');
            DB::statement('CREATE UNIQUE INDEX reservations_boutique_active ON reservations_boutiques (boutique_active)');
        }

        Schema::table('campagnes_boutiques', function (Blueprint $table) {
            $table->json('sites')->nullable();
        });

        Schema::create('boutique_statuts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('boutique_id')->constrained()->restrictOnDelete();
            $table->string('ancien', 20)->nullable();
            $table->string('nouveau', 20);
            $table->foreignId('reservation_boutique_id')->nullable()->constrained('reservations_boutiques')->restrictOnDelete();
            $table->foreignId('auteur_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('motif', 60);
            $table->timestamp('created_at')->useCurrent();
            $table->index(['boutique_id', 'created_at']);
        });
        AppendOnlyTable::protect('boutique_statuts');
    }

    public function down(): void
    {
        Schema::dropIfExists('boutique_statuts');
        Schema::table('campagnes_boutiques', fn (Blueprint $t) => $t->dropColumn('sites'));
        if (DB::getDriverName() !== 'pgsql') {
            DB::statement('DROP INDEX reservations_boutique_active ON reservations_boutiques');
            DB::statement('ALTER TABLE reservations_boutiques DROP COLUMN boutique_active');
        } else {
            DB::statement('DROP INDEX reservations_boutique_active');
        }
        Schema::table('reservations_boutiques', function (Blueprint $table) {
            $table->dropIndex('reservations_piece');
            $table->dropColumn(['type_piece', 'numero_piece', 'reservee_le', 'attribuee_le', 'reference_avance', 'contrat_signe_le']);
        });
        DB::statement('ALTER TABLE paiements DROP CONSTRAINT paiements_compte_destination_coherent');
        foreach (['paiements', 'relances', 'remboursements', 'acceptations'] as $table) {
            Schema::table($table, fn (Blueprint $t) => $t->renameColumn('reservation_boutique_id', 'candidature_boutique_id'));
        }
        Schema::rename('reservations_boutiques', 'candidatures_boutiques');
        DB::statement("ALTER TABLE paiements ADD CONSTRAINT paiements_compte_destination_coherent CHECK (
            (souscription_id IS NOT NULL AND candidature_boutique_id IS NULL AND compte_destination = 'INVESTISSEMENT')
            OR (candidature_boutique_id IS NOT NULL AND souscription_id IS NULL AND compte_destination = 'LOCATION'))");
    }
};
