<?php

namespace App\Models;

use App\Models\Concerns\AppendOnly;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Second tirage d'un lot : graine publiée, ordre des boutiques, boutique affectée à chaque retenu. */
#[Table(name: 'affectations_boutiques', timestamps: false)]
#[Fillable(['tirage_boutiques_id', 'graine', 'ordre_boutiques', 'affectations', 'tire_par', 'created_at'])]
class AffectationBoutiques extends Model
{
    use AppendOnly;

    protected function casts(): array
    {
        return ['ordre_boutiques' => 'array', 'affectations' => 'array', 'created_at' => 'datetime'];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function tireur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'tire_par');
    }
}
