<?php

namespace App\Models;

use App\Enums\StatutDocumentKyc;
use App\Enums\TypeDocumentKyb;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Pièce du dossier KYB d'une institution (PDF privé). Le VALIDATEUR la valide ou la rejette avec un motif.
 */
#[Fillable([
    'institution_id', 'type', 'chemin', 'nom_original', 'empreinte', 'taille', 'depose_par',
    'statut', 'motif_rejet', 'verifie_par', 'verifie_le',
])]
#[Hidden(['chemin'])]
class InstitutionDocument extends Model
{
    protected function casts(): array
    {
        return [
            'type' => TypeDocumentKyb::class,
            'statut' => StatutDocumentKyc::class,
            'verifie_le' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Institution, $this>
     */
    public function institution(): BelongsTo
    {
        return $this->belongsTo(Institution::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function deposant(): BelongsTo
    {
        return $this->belongsTo(User::class, 'depose_par');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function verificateur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verifie_par');
    }
}
