<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Décision sur un dossier « avance en retard », proposée par le RÉGULATEUR avec un motif : prolonger (nouvelle date
 * limite), annuler (boutique remise disponible, remboursement de la caution moins une retenue de 0 à 100 %) ou
 * attendre (simple note, le dossier reste dans la liste). Prolonger et annuler passent par « À valider » : le
 * VALIDATEUR confirme ou rejette, jamais l'auteur de la proposition. Une proposition devient caduque si l'avance est
 * payée entre-temps.
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

    public const DECISIONS = [self::PROLONGER, self::ANNULER, self::ATTENDRE];

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

    /** « Prolonger jusqu'au 15 décembre 2026 », « Annuler (retenue de 10 %) », « Attendre ». */
    public function resume(): string
    {
        return match ($this->decision) {
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
