<?php

namespace App\Models;

use App\Enums\StatutBoutique;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Boutique d'un site : numéro unique sur le site, bloc, surface, type, statut. */
#[Fillable(['site_id', 'type_boutique_id', 'numero', 'bloc', 'surface', 'statut'])]
class Boutique extends Model
{
    protected function casts(): array
    {
        return [
            'surface' => 'decimal:2',
            'statut' => StatutBoutique::class,
        ];
    }

    /**
     * @return BelongsTo<Site, $this>
     */
    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    /**
     * @return BelongsTo<TypeBoutique, $this>
     */
    public function type(): BelongsTo
    {
        return $this->belongsTo(TypeBoutique::class, 'type_boutique_id');
    }
}
