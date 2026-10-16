<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Retenue sur la caution d'une réservation annulée automatiquement faute d'avance : proposée par le RÉGULATEUR (de 0 à
 * 100 %, motif obligatoire), confirmée ou rejetée par le VALIDATEUR dans « À valider », jamais par l'auteur de la
 * proposition. Les anciennes décisions au cas par cas (prolonger, annuler, attendre) restent dans l'historique.
 */
#[Table(name: 'decisions_avances')]
#[Fillable([
    'reservation_boutique_id', 'decision', 'motif', 'nouvelle_date', 'retenue_pct', 'statut', 'propose_par', 'propose_le',
    'decide_par', 'decide_le', 'motif_decision', 'remboursement_id',
])]
class DecisionAvance extends Model
{
    public const PROLONGER = 'PROLONGER';

    public const ANNULER = 'ANNULER';

    public const ATTENDRE = 'ATTENDRE';

    /** Retenue sur la caution à rembourser (seule décision proposée désormais). */
    public const RETENUE = 'RETENUE';

    /** Statuts : proposée (en attente du VALIDATEUR), confirmée, rejetée, notée (« attendre »), caduque (avance payée). */
    public const PROPOSEE = 'PROPOSEE';

    public const CONFIRMEE = 'CONFIRMEE';

    public const REJETEE = 'REJETEE';

    public const NOTEE = 'NOTEE';

    public const CADUQUE = 'CADUQUE';

    protected function casts(): array
    {
        return ['nouvelle_date' => 'datetime', 'retenue_pct' => 'integer', 'propose_le' => 'datetime', 'decide_le' => 'datetime'];
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
    public function auteur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'propose_par');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function decideur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decide_par');
    }

    /**
     * @return BelongsTo<Remboursement, $this>
     */
    public function remboursement(): BelongsTo
    {
        return $this->belongsTo(Remboursement::class);
    }

    /** « Retenue de 10 % » ; anciennes décisions : « Prolonger jusqu'au 15 décembre 2026 », « Annuler (retenue de 10 %) », « Attendre ». */
    public function resume(): string
    {
        return match ($this->decision) {
            self::RETENUE => __('admin.avances.resumes.retenue', ['pct' => (int) $this->retenue_pct]),
            self::PROLONGER => __('admin.avances.resumes.prolonger', ['date' => $this->nouvelle_date?->translatedFormat('j F Y')]),
            self::ANNULER => __('admin.avances.resumes.annuler', ['pct' => (int) $this->retenue_pct]),
            default => __('admin.avances.resumes.attendre'),
        };
    }

    /** Montant remboursé de la caution après la retenue (annulation). */
    public function montantRembourse(): int
    {
        $caution = (int) $this->reservation->caution;

        return $caution - intdiv($caution * (int) $this->retenue_pct, 100);
    }
}
