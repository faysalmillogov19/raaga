<?php

namespace App\Models;

use App\Models\Concerns\AppendOnly;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Table(name: 'campagne_boutiques_versions', timestamps: false)]
#[Fillable(['campagne_boutiques_id', 'numero', 'parametres', 'auteur_id', 'motif', 'created_at'])]
class CampagneBoutiquesVersion extends Model
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
