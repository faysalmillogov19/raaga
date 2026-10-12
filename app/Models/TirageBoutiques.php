<?php

namespace App\Models;

use App\Models\Concerns\AppendOnly;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Résultat figé du tirage au sort d'une catégorie : graine, empreinte des participants, ordre obtenu. */
#[Table(name: 'tirages_boutiques', timestamps: false)]
#[Fillable([
    'campagne_boutiques_id', 'categorie_boutique_id', 'graine', 'empreinte_participants',
    'nombre_boutiques', 'nombre_participants', 'ordre', 'tire_par', 'created_at',
])]
class TirageBoutiques extends Model
{
    use AppendOnly;

    protected function casts(): array
    {
        return [
            'nombre_boutiques' => 'integer',
            'nombre_participants' => 'integer',
            'ordre' => 'array',
            'created_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<CategorieBoutique, $this>
     */
    public function categorie(): BelongsTo
    {
        return $this->belongsTo(CategorieBoutique::class, 'categorie_boutique_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function tireur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'tire_par');
    }
}
