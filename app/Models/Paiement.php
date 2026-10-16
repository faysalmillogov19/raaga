<?php

namespace App\Models;

use App\Casts\AsMoyenPaiement;
use App\Enums\CompteDestination;
use App\Enums\StatutPaiement;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

#[Fillable([
    'souscription_id', 'candidature_boutique_id', 'canal', 'compte_destination', 'montant', 'frais', 'reference', 'reference_fournisseur',
    'statut', 'date_confirmation', 'agent_id', 'cloture_caisse_id', 'metadonnees',
    'controle', 'controle_motif', 'controle_reference', 'controle_par', 'controle_le',
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
            'controle_le' => 'datetime',
        ];
    }

    /** Contrôles d'un paiement manuel par le CONTRÔLEUR DES PAIEMENTS. */
    public const RECU = 'RECU';

    public const INCOMPLET = 'INCOMPLET';

    public const REJETE = 'REJETE';

    /** Paiement manuel (virement, versement, espèces) : un opérateur ne le confirme pas, le contrôleur le marque. */
    public function estManuel(): bool
    {
        return $this->canal?->estManuel() ?? false;
    }

    /**
     * @return HasMany<CorrectionPaiement, $this>
     */
    public function corrections(): HasMany
    {
        return $this->hasMany(CorrectionPaiement::class)->latest('id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function controleur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'controle_par');
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
