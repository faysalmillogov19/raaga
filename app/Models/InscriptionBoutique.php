<?php

namespace App\Models;

use App\Enums\StatutInscriptionBoutique;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Inscription d'un citoyen sur la liste d'attente d'une boutique. Ordre d'arrivée : l'identifiant (les inscriptions
 * d'une campagne s'enregistrent une par une, sous verrou de la campagne). Retenue : le citoyen est l'attributaire de la
 * boutique (réservation liée).
 */
#[Table(name: 'inscriptions_boutiques')]
#[Fillable([
    'campagne_boutiques_id', 'boutique_id', 'user_id', 'statut', 'type_piece', 'numero_piece', 'reservation_boutique_id',
    'inscrite_le', 'retenue_le', 'close_le', 'motif_cloture',
])]
class InscriptionBoutique extends Model
{
    protected function casts(): array
    {
        return [
            'statut' => StatutInscriptionBoutique::class,
            'inscrite_le' => 'datetime',
            'retenue_le' => 'datetime',
            'close_le' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Boutique, $this> */
    public function boutique(): BelongsTo
    {
        return $this->belongsTo(Boutique::class);
    }

    /** @return BelongsTo<CampagneBoutiques, $this> */
    public function campagne(): BelongsTo
    {
        return $this->belongsTo(CampagneBoutiques::class, 'campagne_boutiques_id');
    }

    /** @return BelongsTo<ReservationBoutique, $this> */
    public function reservation(): BelongsTo
    {
        return $this->belongsTo(ReservationBoutique::class, 'reservation_boutique_id');
    }

    /** File d'attente d'une boutique, dans l'ordre d'arrivée. */
    public function scopeFile(Builder $q, int $boutiqueId): Builder
    {
        return $q->where('boutique_id', $boutiqueId)->where('statut', StatutInscriptionBoutique::EnAttente)->orderBy('id');
    }

    /** Rang dans la liste d'attente de la boutique (1 : le prochain retenu), ou null si l'inscription n'attend plus. */
    public function rang(): ?int
    {
        if ($this->statut !== StatutInscriptionBoutique::EnAttente) {
            return null;
        }

        return 1 + self::query()->where('boutique_id', $this->boutique_id)->where('statut', StatutInscriptionBoutique::EnAttente)
            ->where('id', '<', $this->id)->count();
    }
}
