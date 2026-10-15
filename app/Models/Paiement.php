<?php

namespace App\Models;

use App\Casts\AsMoyenPaiement;
use App\Enums\CompteDestination;
use App\Enums\StatutPaiement;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

#[Fillable([
    'souscription_id', 'candidature_boutique_id', 'canal', 'compte_destination', 'montant', 'frais', 'reference', 'reference_fournisseur',
    'statut', 'date_confirmation', 'agent_id', 'cloture_caisse_id', 'metadonnees',
])]
class Paiement extends Model
{
    /**
     * Compte de destination : déduit de l'objet réglé (actions : investissement ; caution : location) et jamais
     * contradictoire avec lui (contrôlé ici, et par une contrainte en base).
     */
    protected static function booted(): void
    {
        static::saving(function (Paiement $p) {
            $attendu = $p->souscription_id !== null ? CompteDestination::Investissement : ($p->candidature_boutique_id !== null ? CompteDestination::Location : null);
            $p->compte_destination ??= $attendu;
            if ($attendu === null || $p->compte_destination !== $attendu) {
                throw new LogicException(__('public.paiement.erreurs_compte'));
            }
        });
    }

    protected function casts(): array
    {
        return [
            'canal' => AsMoyenPaiement::class,
            'compte_destination' => CompteDestination::class,
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
