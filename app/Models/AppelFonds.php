<?php

namespace App\Models;

use App\Enums\StatutAppelFonds;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Appel de fonds d'une convention : montant à verser par virement avant l'échéance, avec une
 * référence de virement obligatoire. À PAYER → DÉCLARÉ → REÇU, ou PARTIEL, ou EN RETARD.
 */
#[Table(name: 'appels_fonds')]
#[Fillable([
    'convention_id', 'institution_id', 'numero', 'libelle', 'echeance', 'montant', 'montant_recu',
    'statut', 'reference_virement', 'recu_le', 'rappels',
])]
class AppelFonds extends Model
{
    protected function casts(): array
    {
        return [
            'echeance' => 'date',
            'montant' => 'integer',
            'montant_recu' => 'integer',
            'statut' => StatutAppelFonds::class,
            'recu_le' => 'datetime',
            'rappels' => 'array',
        ];
    }

    public function resteAPayer(): int
    {
        return max(0, $this->montant - $this->montant_recu);
    }

    public function estSolde(): bool
    {
        return $this->statut === StatutAppelFonds::Recu;
    }

    /**
     * @return BelongsTo<Convention, $this>
     */
    public function convention(): BelongsTo
    {
        return $this->belongsTo(Convention::class);
    }

    /**
     * @return BelongsTo<Institution, $this>
     */
    public function institution(): BelongsTo
    {
        return $this->belongsTo(Institution::class);
    }

    /**
     * @return HasMany<DeclarationVirement, $this>
     */
    public function declarations(): HasMany
    {
        return $this->hasMany(DeclarationVirement::class, 'appel_fonds_id')->latest('id');
    }
}
