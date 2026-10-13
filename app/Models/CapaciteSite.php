<?php

namespace App\Models;

use App\Enums\NiveauBoutique;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Capacité prévue d'un site pour un niveau (facultatif), une zone (facultative) et un type de local : nombre et
 * surface unitaire, ou seulement surface totale. La surface retenue se calcule : surface totale saisie, sinon
 * nombre × surface unitaire. Une capacité peut rester incomplète : elle est alors signalée « à compléter ».
 */
#[Table(name: 'capacites_sites')]
#[Fillable(['site_id', 'niveau', 'zone', 'type_boutique_id', 'nombre_prevu', 'surface_unitaire', 'surface_totale', 'source', 'observations'])]
class CapaciteSite extends Model
{
    protected function casts(): array
    {
        return [
            'niveau' => NiveauBoutique::class,
            'nombre_prevu' => 'integer',
            'surface_unitaire' => 'decimal:2',
            'surface_totale' => 'decimal:2',
        ];
    }

    public function surfaceRetenue(): ?float
    {
        if ($this->surface_totale !== null) {
            return (float) $this->surface_totale;
        }

        return $this->nombre_prevu !== null && $this->surface_unitaire !== null ? $this->nombre_prevu * (float) $this->surface_unitaire : null;
    }

    public function estIncomplete(): bool
    {
        return $this->nombre_prevu === null && $this->surfaceRetenue() === null;
    }

    /**
     * @return BelongsTo<TypeBoutique, $this>
     */
    public function type(): BelongsTo
    {
        return $this->belongsTo(TypeBoutique::class, 'type_boutique_id');
    }

    /**
     * @return BelongsTo<Site, $this>
     */
    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }
}
