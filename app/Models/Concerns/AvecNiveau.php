<?php

namespace App\Models\Concerns;

use App\Models\Niveau;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Modèle qui porte un code de niveau (colonne « niveau », clé étrangère vers niveaux.code). Le code reste affiché
 * et utilisable même si le niveau a été désactivé depuis.
 */
trait AvecNiveau
{
    /**
     * @return BelongsTo<Niveau, $this>
     */
    public function niveauRef(): BelongsTo
    {
        return $this->belongsTo(Niveau::class, 'niveau', 'code');
    }

    /** Libellé du niveau (« R+1 »), ou son code s'il manque, ou null sans niveau. */
    public function libelleNiveau(): ?string
    {
        return $this->niveau === null ? null : ($this->niveauRef?->libelle ?? $this->niveau);
    }
}
