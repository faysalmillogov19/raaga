<?php

namespace App\Models;

use App\Enums\StatutAvenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Modification d'un paramétrage déjà confirmé : soumise par le RÉGULATEUR, confirmée ou rejetée
 * par le VALIDATEUR, appliquée seulement après confirmation.
 */
#[Fillable([
    'avenantable_type', 'avenantable_id', 'ancienne_valeur', 'nouvelle_valeur', 'motif', 'statut',
    'saisi_par', 'decide_par', 'decide_le', 'motif_decision',
])]
class Avenant extends Model
{
    protected function casts(): array
    {
        return [
            'ancienne_valeur' => 'array',
            'nouvelle_valeur' => 'array',
            'statut' => StatutAvenant::class,
            'decide_le' => 'datetime',
        ];
    }

    public function avenantable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function auteur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'saisi_par');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function decideur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decide_par');
    }
}
