<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Lot d'une campagne : un site × un type de boutique ; nombre de boutiques (compté dans le référentiel) et caution. */
#[Table(name: 'categories_boutiques')]
#[Fillable(['campagne_boutiques_id', 'site_id', 'type_boutique_id', 'ordre', 'libelle', 'description', 'nombre_boutiques', 'caution'])]
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

    /** « Petite boutique — Marché central » (le site est omis pour un lot sans site). */
    public function intitule(): string
    {
        return $this->libelle.($this->site ? ' — '.$this->site->nom : '');
    }
}
