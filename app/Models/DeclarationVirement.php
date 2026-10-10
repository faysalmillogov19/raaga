<?php

namespace App\Models;

use App\Enums\StatutDeclarationVirement;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Virement déclaré par le signataire pour un appel de fonds, rapproché par le VALIDATEUR avec le
 * relevé du compte séquestre (ou rejeté s'il n'y figure pas).
 */
#[Table(name: 'declarations_virement')]
#[Fillable([
    'appel_fonds_id', 'institution_id', 'date_virement', 'montant', 'reference', 'avis_chemin', 'avis_empreinte',
    'declare_par', 'statut', 'montant_recu', 'recu_le', 'reference_releve', 'motif_rejet', 'rapproche_par', 'rapproche_le',
])]
#[Hidden(['avis_chemin'])]
class DeclarationVirement extends Model
{
    protected function casts(): array
    {
        return [
            'date_virement' => 'date',
            'montant' => 'integer',
            'montant_recu' => 'integer',
            'recu_le' => 'date',
            'statut' => StatutDeclarationVirement::class,
            'rapproche_le' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<AppelFonds, $this>
     */
    public function appel(): BelongsTo
    {
        return $this->belongsTo(AppelFonds::class, 'appel_fonds_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function declarant(): BelongsTo
    {
        return $this->belongsTo(User::class, 'declare_par');
    }
}
