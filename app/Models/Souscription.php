<?php

namespace App\Models;

use App\Enums\CanalSouscription;
use App\Enums\StatutRevue;
use App\Enums\StatutSouscription;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'reference', 'user_id', 'tranche_id', 'nb_actions_demandees', 'valeur_unitaire', 'montant',
    'frais_souscription', 'canal', 'agent_id', 'statut', 'revue_statut', 'revue_par', 'revue_le',
    'revue_motif', 'nb_actions_allouees', 'montant_a_rembourser', 'expire_le', 'payee_le', 'allouee_le',
])]
class Souscription extends Model
{
    protected function casts(): array
    {
        return [
            'nb_actions_demandees' => 'integer',
            'valeur_unitaire' => 'integer',
            'montant' => 'integer',
            'frais_souscription' => 'integer',
            'canal' => CanalSouscription::class,
            'statut' => StatutSouscription::class,
            'revue_statut' => StatutRevue::class,
            'revue_le' => 'datetime',
            'nb_actions_allouees' => 'integer',
            'montant_a_rembourser' => 'integer',
            'expire_le' => 'datetime',
            'payee_le' => 'datetime',
            'allouee_le' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<Tranche, $this>
     */
    public function tranche(): BelongsTo
    {
        return $this->belongsTo(Tranche::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function agent(): BelongsTo
    {
        return $this->belongsTo(User::class, 'agent_id');
    }

    /**
     * @return HasMany<Paiement, $this>
     */
    public function paiements(): HasMany
    {
        return $this->hasMany(Paiement::class);
    }

    /**
     * @return HasMany<Acceptation, $this>
     */
    public function acceptations(): HasMany
    {
        return $this->hasMany(Acceptation::class);
    }
}
