<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Listes d'attente des boutiques : un citoyen s'inscrit sur plusieurs boutiques ; le premier inscrit sur une boutique
 * libre en devient attributaire (réservation pré-réservée, caution à payer), les suivants attendent dans l'ordre
 * d'arrivée. Une seule inscription active (en attente ou retenue) par citoyen et par boutique. Les réservations en
 * cours deviennent des inscriptions retenues, à leur date d'origine.
 */
return new class extends Migration
{
    private const ACTIVES = "'EN_ATTENTE','RETENUE'";

    public function up(): void
    {
        $pgsql = DB::getDriverName() === 'pgsql';

        Schema::create('inscriptions_boutiques', function (Blueprint $table) {
            $table->id();
            $table->foreignId('campagne_boutiques_id')->constrained('campagnes_boutiques')->restrictOnDelete();
            $table->foreignId('boutique_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('statut', 20);
            $table->string('type_piece', 20)->nullable();
            $table->string('numero_piece', 40)->nullable();
            $table->foreignId('reservation_boutique_id')->nullable()->constrained('reservations_boutiques')->restrictOnDelete();
            // Heure d'inscription (affichée) ; l'ordre d'arrivée est celui des identifiants, attribués sous verrou de la campagne.
            $table->timestamp('inscrite_le');
            $table->timestamp('retenue_le')->nullable();
            $table->timestamp('close_le')->nullable();
            $table->string('motif_cloture', 40)->nullable();
            $table->timestamps();
            $table->index(['boutique_id', 'statut'], 'inscriptions_file');
            $table->index(['campagne_boutiques_id', 'user_id'], 'inscriptions_citoyen');
        });

        if ($pgsql) {
            DB::statement('CREATE UNIQUE INDEX inscriptions_boutique_active ON inscriptions_boutiques (boutique_id, user_id) WHERE statut IN ('.self::ACTIVES.')');
        } else {
            DB::statement('ALTER TABLE inscriptions_boutiques ADD COLUMN boutique_active BIGINT UNSIGNED AS (CASE WHEN statut IN ('.self::ACTIVES.') THEN boutique_id END) STORED');
            DB::statement('CREATE UNIQUE INDEX inscriptions_boutique_active ON inscriptions_boutiques (boutique_active, user_id)');
        }

        // Engagements acceptés à l'inscription (avant toute réservation).
        Schema::table('acceptations', function (Blueprint $table) {
            $table->foreignId('inscription_boutique_id')->nullable()->constrained('inscriptions_boutiques')->restrictOnDelete();
        });

        // Réservations existantes : inscriptions retenues (en cours ou attribuées), perdues sinon (historique).
        DB::table('reservations_boutiques')->whereNotNull('boutique_id')->whereNotNull('campagne_boutiques_id')->orderBy('id')
            ->each(function (object $r) {
                $active = in_array($r->statut, ['PRE_RESERVEE', 'RESERVEE', 'ATTRIBUEE'], true);
                DB::table('inscriptions_boutiques')->insert([
                    'campagne_boutiques_id' => $r->campagne_boutiques_id, 'boutique_id' => $r->boutique_id, 'user_id' => $r->user_id,
                    'statut' => $active ? 'RETENUE' : 'PERDUE', 'type_piece' => $r->type_piece, 'numero_piece' => $r->numero_piece,
                    'reservation_boutique_id' => $r->id, 'inscrite_le' => $r->created_at, 'retenue_le' => $r->created_at,
                    'close_le' => $active ? null : $r->updated_at, 'created_at' => $r->created_at, 'updated_at' => $r->updated_at,
                ]);
            });
    }

    public function down(): void
    {
        Schema::table('acceptations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('inscription_boutique_id');
        });
        Schema::dropIfExists('inscriptions_boutiques');
    }
};
