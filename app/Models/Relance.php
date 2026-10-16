<?php

namespace App\Models;

use App\Models\Concerns\AppendOnly;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Relance d'un souscripteur ou d'un commerçant dont le paiement est attendu (ajout seul) : 24 h avant la fin du délai
 * (ECHEANCE), à l'expiration (EXPIRATION) ou par le CONTRÔLEUR DES PAIEMENTS (MANUELLE). Auteur null : automatique.
 */
#[Table(name: 'relances', timestamps: false)]
#[Fillable(['souscription_id', 'reservation_boutique_id', 'user_id', 'type', 'canaux', 'auteur_id', 'message', 'created_at'])]
class Relance extends Model
{
    use AppendOnly;

    public const ECHEANCE = 'ECHEANCE';

    public const EXPIRATION = 'EXPIRATION';

    public const MANUELLE = 'MANUELLE';

    /** 24 h avant la fin du délai de complément d'un paiement incomplet. */
    public const COMPLEMENT = 'COMPLEMENT';

    protected function casts(): array
    {
        return ['canaux' => 'array', 'created_at' => 'datetime'];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function auteur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'auteur_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
