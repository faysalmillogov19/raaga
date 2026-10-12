<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Catégorie de boutiques d'une campagne : nombre de boutiques à attribuer et caution exigée. */
#[Table(name: 'categories_boutiques')]
#[Fillable(['campagne_boutiques_id', 'ordre', 'libelle', 'description', 'nombre_boutiques', 'caution'])]
class CategorieBoutique extends Model
{
    protected function casts(): array
    {
        return [
            'ordre' => 'integer',
            'nombre_boutiques' => 'integer',
            'caution' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<CampagneBoutiques, $this>
     */
    public function campagne(): BelongsTo
    {
        return $this->belongsTo(CampagneBoutiques::class, 'campagne_boutiques_id');
    }
}
