<?php

namespace App\Models;

use App\Contracts\Payable;
use App\Enums\CanalPaiement;
use App\Enums\StatutCandidatureBoutique;
use App\Support\Recu;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Candidature d'un citoyen à une catégorie de boutiques ; la caution est figée à la candidature. */
#[Table(name: 'candidatures_boutiques')]
#[Fillable([
    'reference', 'user_id', 'campagne_boutiques_id', 'categorie_boutique_id', 'boutique_id', 'caution', 'statut',
    'expire_le', 'payee_le', 'rang_tirage', 'tiree_le',
])]
class CandidatureBoutique extends Model implements Payable
{
    protected function casts(): array
    {
        return [
            'caution' => 'integer',
            'statut' => StatutCandidatureBoutique::class,
            'expire_le' => 'datetime',
            'payee_le' => 'datetime',
            'rang_tirage' => 'integer',
            'tiree_le' => 'datetime',
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
     * @return BelongsTo<CampagneBoutiques, $this>
     */
    public function campagne(): BelongsTo
    {
        return $this->belongsTo(CampagneBoutiques::class, 'campagne_boutiques_id');
    }

    /**
     * @return BelongsTo<CategorieBoutique, $this>
     */
    public function categorie(): BelongsTo
    {
        return $this->belongsTo(CategorieBoutique::class, 'categorie_boutique_id');
    }

    /** Boutique précise attribuée au second tirage. */
    public function boutique(): BelongsTo
    {
        return $this->belongsTo(Boutique::class);
    }

    /**
     * @return HasMany<Paiement, $this>
     */
    public function paiements(): HasMany
    {
        return $this->hasMany(Paiement::class, 'candidature_boutique_id');
    }

    public static function clePaiement(): string
    {
        return 'candidature_boutique_id';
    }

    public function montantAPayer(): int
    {
        return $this->caution;
    }

    public function fraisAPayer(): int
    {
        return 0;
    }

    public function attendPaiement(): bool
    {
        return $this->statut === StatutCandidatureBoutique::Reservee;
    }

    public function marquerPaye(): void
    {
        $this->forceFill(['statut' => StatutCandidatureBoutique::Payee, 'payee_le' => now()])->save();
    }

    public function estExpire(): bool
    {
        return $this->statut === StatutCandidatureBoutique::Expiree;
    }

    /** @return list<CanalPaiement> */
    public function canauxPaiement(): array
    {
        return $this->campagne->canaux();
    }

    public function smsPaiementConfirme(Paiement $paiement): array
    {
        return [__('public.boutiques.sms.caution_payee'), [
            'recu' => Recu::numero($paiement),
            'reference' => $this->reference,
            'montant' => format_fcfa($paiement->montant),
        ]];
    }

    /**
     * @return HasMany<Acceptation, $this>
     */
    public function acceptations(): HasMany
    {
        return $this->hasMany(Acceptation::class, 'candidature_boutique_id');
    }
}
