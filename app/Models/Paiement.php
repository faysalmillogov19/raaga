<?php

namespace App\Models;

use App\Enums\CanalPaiement;
use App\Enums\StatutPaiement;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'souscription_id', 'candidature_boutique_id', 'canal', 'montant', 'frais', 'reference', 'reference_fournisseur',
    'statut', 'date_confirmation', 'agent_id', 'cloture_caisse_id', 'metadonnees',
])]
class Paiement extends Model
{
    protected function casts(): array
    {
        return [
            'canal' => CanalPaiement::class,
            'montant' => 'integer',
            'frais' => 'integer',
            'statut' => StatutPaiement::class,
            'date_confirmation' => 'datetime',
            'metadonnees' => 'array',
        ];
    }

    /**
     * @return BelongsTo<Souscription, $this>
     */
    public function souscription(): BelongsTo
    {
        return $this->belongsTo(Souscription::class);
    }

    /**
     * @return BelongsTo<CandidatureBoutique, $this>
     */
    public function candidature(): BelongsTo
    {
        return $this->belongsTo(CandidatureBoutique::class, 'candidature_boutique_id');
    }

    /** Objet réglé par ce paiement (souscription d'actions ou caution d'une candidature). */
    public function objet(): Souscription|CandidatureBoutique
    {
        return $this->souscription_id ? $this->souscription : $this->candidature;
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function agent(): BelongsTo
    {
        return $this->belongsTo(User::class, 'agent_id');
    }

    /**
     * @return BelongsTo<ClotureCaisse, $this>
     */
    public function clotureCaisse(): BelongsTo
    {
        return $this->belongsTo(ClotureCaisse::class);
    }
}
