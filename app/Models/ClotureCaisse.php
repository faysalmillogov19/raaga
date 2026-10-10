<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Table(name: 'clotures_caisse')]
#[Fillable(['agent_id', 'date_caisse', 'nb_encaissements', 'total_montant', 'cloturee_le'])]
class ClotureCaisse extends Model
{
    protected function casts(): array
    {
        return [
            'date_caisse' => 'date',
            'nb_encaissements' => 'integer',
            'total_montant' => 'integer',
            'cloturee_le' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function agent(): BelongsTo
    {
        return $this->belongsTo(User::class, 'agent_id');
    }

    /**
     * @return HasMany<Paiement, $this>
     */
    public function paiements(): HasMany
    {
        return $this->hasMany(Paiement::class);
    }
}
