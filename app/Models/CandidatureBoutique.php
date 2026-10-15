<?php

namespace App\Models;

use App\Contracts\Payable;
use App\Enums\StatutCandidatureBoutique;
use App\Support\MontantsBoutique;
use App\Support\Recu;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Candidature d'un citoyen à un lot de boutiques. Caution et avance sont deux montants distincts, figés à la
 * candidature (copiés du lot) : le montant à payer est leur somme, mais ils s'affichent toujours séparément.
 */
#[Table(name: 'candidatures_boutiques')]
#[Fillable([
    'reference', 'user_id', 'campagne_boutiques_id', 'categorie_boutique_id', 'boutique_id', 'caution', 'avance',
    'loyer_mensuel', 'mois_caution', 'mois_avance', 'statut', 'expire_le', 'payee_le', 'rang_tirage', 'tiree_le',
])]
class CandidatureBoutique extends Model implements Payable
{
    protected function casts(): array
    {
        return [
            'caution' => 'integer',
            'avance' => 'integer',
            'loyer_mensuel' => 'integer',
            'mois_caution' => 'integer',
            'mois_avance' => 'integer',
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
        return $this->caution + $this->avance;
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

    /** @return list<MoyenPaiement> */
    public function canauxPaiement(): array
    {
        return $this->campagne->canaux($this->montantAPayer() + $this->fraisAPayer());
    }

    public function smsPaiementConfirme(Paiement $paiement): array
    {
        return [__('public.boutiques.sms.'.($this->avance > 0 ? 'caution_avance_payee' : 'caution_payee')), [
            'recu' => Recu::numero($paiement),
            'reference' => $this->reference,
            'montant' => format_fcfa($paiement->montant),
            'caution' => format_fcfa($this->caution),
            'avance' => format_fcfa($this->avance),
        ]];
    }

    /** @return list<array{libelle: string, montant: int, mention: string}> */
    public function lignesMontants(): array
    {
        return MontantsBoutique::lignes($this->caution, $this->avance, $this->mois_avance);
    }

    /**
     * @return HasMany<Acceptation, $this>
     */
    public function acceptations(): HasMany
    {
        return $this->hasMany(Acceptation::class, 'candidature_boutique_id');
    }
}
