<?php

namespace App\Models;

use App\Models\Concerns\AppendOnly;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Table(name: 'lignes_registre', timestamps: false)]
#[Fillable([
    'numero_certificat', 'user_id', 'tranche_id', 'souscription_id', 'nb_actions',
    'numero_action_debut', 'numero_action_fin', 'date_inscription', 'created_at',
])]
class LigneRegistre extends Model
{
    use AppendOnly;

    protected function casts(): array
    {
        return [
            'nb_actions' => 'integer',
            'numero_action_debut' => 'integer',
            'numero_action_fin' => 'integer',
            'date_inscription' => 'datetime',
            'created_at' => 'datetime',
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
     * @return BelongsTo<Tranche, $this>
     */
    public function tranche(): BelongsTo
    {
        return $this->belongsTo(Tranche::class);
    }

    /**
     * @return BelongsTo<Souscription, $this>
     */
    public function souscription(): BelongsTo
    {
        return $this->belongsTo(Souscription::class);
    }
}
