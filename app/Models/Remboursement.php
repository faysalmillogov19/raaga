<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * Remboursement d'un paiement (souscription annulée, caution d'une candidature annulée) : ouvert automatiquement,
 * suivi par le CONTRÔLEUR DES PAIEMENTS qui déclare son exécution (mode, référence), confirmé par le VALIDATEUR.
 * A_EFFECTUER → PROPOSE → EFFECTUE ; un rejet le renvoie à A_EFFECTUER avec le motif.
 */
#[Fillable([
    'reference', 'souscription_id', 'reservation_boutique_id', 'paiement_id', 'user_id', 'montant', 'origine', 'statut',
    'mode', 'reference_operation', 'propose_par', 'propose_le', 'valide_par', 'valide_le', 'motif_rejet',
])]
class Remboursement extends Model
{
    public const A_EFFECTUER = 'A_EFFECTUER';

    public const PROPOSE = 'PROPOSE';

    public const EFFECTUE = 'EFFECTUE';

    public const ANNULATION_IDENTITE = 'ANNULATION_IDENTITE';

    public const COMPLEMENT_NON_RECU = 'COMPLEMENT_NON_RECU';

    protected function casts(): array
    {
        return ['montant' => 'integer', 'propose_le' => 'datetime', 'valide_le' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::creating(fn (self $r) => $r->reference ??= self::nouvelleReference());
    }

    /** « RMB-2026-7K3QX9 ». */
    public static function nouvelleReference(): string
    {
        do {
            $reference = 'RMB-'.now()->format('Y').'-'.Str::upper(Str::random(6));
        } while (static::query()->where('reference', $reference)->exists());

        return $reference;
    }

    /** Libellé du statut (« À effectuer », « Exécution déclarée », « Effectué »). */
    public function libelleStatut(): string
    {
        return __('admin.remboursements.statuts.'.$this->statut);
    }

    /** Objet remboursé : souscription d'actions ou candidature aux boutiques (caution). */
    public function objet(): Souscription|ReservationBoutique|null
    {
        return $this->souscription_id ? $this->souscription : $this->reservation;
    }

    /**
     * @return BelongsTo<Souscription, $this>
     */
    public function souscription(): BelongsTo
    {
        return $this->belongsTo(Souscription::class);
    }

    /**
     * @return BelongsTo<ReservationBoutique, $this>
     */
    public function reservation(): BelongsTo
    {
        return $this->belongsTo(ReservationBoutique::class, 'reservation_boutique_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function auteur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'propose_par');
    }
}
