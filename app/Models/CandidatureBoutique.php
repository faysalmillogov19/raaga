<?php

namespace App\Models;

use App\Enums\StatutCandidatureBoutique;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Candidature d'un citoyen à une catégorie de boutiques ; la caution est figée à la candidature. */
#[Table(name: 'candidatures_boutiques')]
#[Fillable([
    'reference', 'user_id', 'campagne_boutiques_id', 'categorie_boutique_id', 'caution', 'statut',
    'expire_le', 'payee_le', 'rang_tirage', 'tiree_le',
])]
class CandidatureBoutique extends Model
{
    protected function casts(): array
    {
        return [
            'caution' => 'integer',
            'statut' => StatutCandidatureBoutique::class,
            'expire_le' => 'datetime',
            'payee_le' => 'datetime',
            'rang_tirage' => 'integer',
            'tiree_le' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<CampagneBoutiques, $this>
     */
    public function campagne(): BelongsTo
    {
        return $this->belongsTo(CampagneBoutiques::class, 'campagne_boutiques_id');
    }

    /**
     * @return BelongsTo<CategorieBoutique, $this>
     */
    public function categorie(): BelongsTo
    {
        return $this->belongsTo(CategorieBoutique::class, 'categorie_boutique_id');
    }

    /**
     * @return HasMany<Acceptation, $this>
     */
    public function acceptations(): HasMany
    {
        return $this->hasMany(Acceptation::class, 'candidature_boutique_id');
    }
}
