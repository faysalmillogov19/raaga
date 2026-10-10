<?php

namespace App\Models;

use App\Models\Concerns\AppendOnly;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Table(timestamps: false)]
#[Fillable([
    'acteur_id', 'action', 'entite_type', 'entite_id', 'ancienne_valeur', 'nouvelle_valeur', 'ip', 'created_at',
])]
class AuditLog extends Model
{
    use AppendOnly;

    protected function casts(): array
    {
        return [
            'ancienne_valeur' => 'array',
            'nouvelle_valeur' => 'array',
            'created_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function acteur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'acteur_id');
    }
}
