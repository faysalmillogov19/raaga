<?php

namespace App\Models;

use App\Enums\DecisionParametrage;
use App\Models\Concerns\AppendOnly;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Décision du VALIDATEUR sur une version soumise (confirmation ou rejet) · ajout seul.
 */
#[Table(timestamps: false)]
#[Fillable(['objet_type', 'objet_id', 'version', 'decision', 'motif', 'decideur_id', 'created_at'])]
class ParametrageDecision extends Model
{
    use AppendOnly;

    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'decision' => DecisionParametrage::class,
            'created_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function decideur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decideur_id');
    }
}
