<?php

namespace App\Models;

use App\Models\Concerns\AppendOnly;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Version figée d'un paramétrage de distribution (créée à chaque soumission et avenant) — ajout seul.
 */
#[Table(timestamps: false)]
#[Fillable(['distribution_id', 'numero', 'parametres', 'auteur_id', 'motif', 'created_at'])]
class DistributionVersion extends Model
{
    use AppendOnly;

    protected function casts(): array
    {
        return [
            'numero' => 'integer',
            'parametres' => 'array',
            'created_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function auteur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'auteur_id');
    }
}
