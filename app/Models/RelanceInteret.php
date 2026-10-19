<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Relance de la liste d'attente générale d'une campagne : boutiques libérées sans personne à retenir, ouvertes aux
 * souscripteurs non attributaires jusqu'à fin_le. Close : boutiques restées libres (restantes), RÉGULATEUR prévenu si
 * la campagne ne prend plus de réservations.
 */
#[Table(name: 'relances_interet')]
#[Fillable(['campagne_boutiques_id', 'boutiques', 'destinataires', 'ouverte_le', 'fin_le', 'cloturee_le', 'restantes', 'regulateur_notifie_le'])]
class RelanceInteret extends Model
{
    protected function casts(): array
    {
        return [
            'boutiques' => 'array',
            'restantes' => 'array',
            'destinataires' => 'integer',
            'ouverte_le' => 'datetime',
            'fin_le' => 'datetime',
            'cloturee_le' => 'datetime',
            'regulateur_notifie_le' => 'datetime',
        ];
    }

    /** @return BelongsTo<CampagneBoutiques, $this> */
    public function campagne(): BelongsTo
    {
        return $this->belongsTo(CampagneBoutiques::class, 'campagne_boutiques_id');
    }

    public function enCours(): bool
    {
        return $this->cloturee_le === null && $this->fin_le->isFuture();
    }
}
