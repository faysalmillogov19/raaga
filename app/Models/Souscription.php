<?php

namespace App\Models;

use App\Contracts\Payable;
use App\Enums\CanalSouscription;
use App\Enums\CompteDestination;
use App\Enums\StatutRevue;
use App\Enums\StatutSouscription;
use App\Enums\UsageMoyenPaiement;
use App\Support\Recu;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'reference', 'user_id', 'tranche_id', 'nb_actions_demandees', 'valeur_unitaire', 'montant',
    'frais_souscription', 'canal', 'agent_id', 'statut', 'revue_statut', 'revue_par', 'revue_le',
    'revue_motif', 'nb_actions_allouees', 'montant_a_rembourser', 'expire_le', 'payee_le', 'allouee_le',
])]
class Souscription extends Model implements Payable
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
     * @return HasMany<Relance, $this>
     */
    public function relances(): HasMany
    {
        return $this->hasMany(Relance::class, 'souscription_id');
    }

    /**
     * @return HasMany<Remboursement, $this>
     */
    public function remboursements(): HasMany
    {
        return $this->hasMany(Remboursement::class, 'souscription_id');
    }

    /**
     * @return HasMany<Paiement, $this>
     */
    public function paiements(): HasMany
    {
        return $this->hasMany(Paiement::class);
    }

    public static function clePaiement(): string
    {
        return 'souscription_id';
    }

    public function compteDestination(): CompteDestination
    {
        return CompteDestination::Investissement;
    }

    public function montantAPayer(): int
    {
        return $this->montant;
    }

    public function fraisAPayer(): int
    {
        return $this->frais_souscription;
    }

    public function attendPaiement(): bool
    {
        return $this->statut === StatutSouscription::Reservee;
    }

    public function marquerPaye(): void
    {
        $this->forceFill(['statut' => StatutSouscription::Payee, 'payee_le' => now()])->save();
    }

    public function estExpire(): bool
    {
        return $this->statut === StatutSouscription::Expiree;
    }

    public function echeancePaiement(): ?CarbonInterface
    {
        return $this->expire_le;
    }

    public function naturePaiementAttendue(): ?string
    {
        return null;
    }

    /** @return list<MoyenPaiement> moyens retenus par la tranche, autorisés pour les actions et dont les plafonds couvrent le montant */
    public function canauxPaiement(): array
    {
        return MoyenPaiement::pourPaiement((array) $this->tranche->canaux_actifs, UsageMoyenPaiement::Actions, $this->montantAPayer() + $this->fraisAPayer());
    }

    public function smsPaiementConfirme(Paiement $paiement): array
    {
        return [__('public.recu.sms'), [
            'recu' => Recu::numero($paiement),
            'reference' => $this->reference,
            'actions' => format_nombre($this->nb_actions_demandees),
            'montant' => format_fcfa($paiement->montant + $paiement->frais),
        ]];
    }

    /**
     * @return HasMany<Acceptation, $this>
     */
    public function acceptations(): HasMany
    {
        return $this->hasMany(Acceptation::class);
    }
}
