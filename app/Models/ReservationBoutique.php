<?php

namespace App\Models;

use App\Contracts\Payable;
use App\Enums\CompteDestination;
use App\Enums\StatutBoutique;
use App\Enums\StatutPaiement;
use App\Enums\StatutReservationBoutique;
use App\Support\MontantsBoutique;
use App\Support\Recu;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Réservation d'une boutique précise, au premier arrivé : pré-réservée à la confirmation (caution à payer dans le délai
 * de la campagne), réservée une fois la caution payée, puis avance à payer avant sa date limite (même circuit de
 * paiement ; non payée à temps, la réservation est annulée automatiquement et la boutique redevient disponible),
 * attribuée quand l'avance est confirmée et le contrat signé. Montants
 * figés à la réservation depuis la grille tarifaire confirmée du site : seule la caution confirme la réservation ;
 * l'avance est due avant la signature du contrat. Une seule réservation active par boutique (index unique en base).
 */
#[Table(name: 'reservations_boutiques')]
#[Fillable([
    'reference', 'user_id', 'campagne_boutiques_id', 'boutique_id', 'caution', 'avance',
    'loyer_mensuel', 'mois_caution', 'mois_avance', 'statut', 'expire_le', 'payee_le',
    'type_piece', 'numero_piece', 'reservee_le', 'avance_due_le', 'avance_payee_le', 'annulation_motif', 'attribuee_le', 'reference_avance', 'contrat_signe_le',
])]
class ReservationBoutique extends Model implements Payable
{
    protected function casts(): array
    {
        return [
            'caution' => 'integer',
            'avance' => 'integer',
            'loyer_mensuel' => 'integer',
            'mois_caution' => 'integer',
            'mois_avance' => 'integer',
            'statut' => StatutReservationBoutique::class,
            'expire_le' => 'datetime',
            'payee_le' => 'datetime',
            'reservee_le' => 'datetime',
            'avance_due_le' => 'datetime',
            'avance_payee_le' => 'datetime',
            'attribuee_le' => 'datetime',
            'contrat_signe_le' => 'date',
        ];
    }

    /** Numéro de pièce normalisé (majuscules, sans espace ni tiret) : la limite par personne s'y applique. */
    public static function normaliserPiece(string $numero): string
    {
        return preg_replace('/[^A-Z0-9]/', '', mb_strtoupper($numero)) ?? '';
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
     * @return BelongsTo<Boutique, $this>
     */
    public function boutique(): BelongsTo
    {
        return $this->belongsTo(Boutique::class);
    }

    /**
     * @return HasMany<Relance, $this>
     */
    public function relances(): HasMany
    {
        return $this->hasMany(Relance::class, 'reservation_boutique_id');
    }

    /**
     * @return HasMany<Remboursement, $this>
     */
    public function remboursements(): HasMany
    {
        return $this->hasMany(Remboursement::class, 'reservation_boutique_id');
    }

    /**
     * @return HasMany<Paiement, $this>
     */
    public function paiements(): HasMany
    {
        return $this->hasMany(Paiement::class, 'reservation_boutique_id');
    }

    public static function clePaiement(): string
    {
        return 'reservation_boutique_id';
    }

    public function compteDestination(): CompteDestination
    {
        return CompteDestination::Location;
    }

    /**
     * Paiement attendu : la caution d'une boutique pré-réservée ; l'avance d'une boutique réservée tant qu'elle n'est pas
     * confirmée (en retard compris) ; rien ensuite.
     */
    public function naturePaiementAttendue(): ?string
    {
        return match (true) {
            $this->statut === StatutReservationBoutique::PreReservee => Paiement::CAUTION,
            $this->statut === StatutReservationBoutique::Reservee && $this->avance > 0 && $this->avance_payee_le === null => Paiement::AVANCE,
            default => null,
        };
    }

    /** Montant du paiement attendu : la caution, puis l'avance (figée depuis la grille confirmée). */
    public function montantAPayer(): int
    {
        return $this->naturePaiementAttendue() === Paiement::AVANCE ? (int) $this->avance : (int) $this->caution;
    }

    public function echeancePaiement(): ?CarbonInterface
    {
        return $this->naturePaiementAttendue() === Paiement::AVANCE ? $this->avance_due_le : $this->expire_le;
    }

    /** Motif d'annulation : avance non payée dans le délai (annulation automatique à la date limite). */
    public const ANNULATION_AVANCE = 'AVANCE_NON_PAYEE';

    /** Avance attendue et date limite dépassée, sans preuve en contrôle : la réservation est à annuler. */
    public function avanceEchue(): bool
    {
        return $this->naturePaiementAttendue() === Paiement::AVANCE && $this->avance_due_le?->isPast() && ! $this->paiements()->enControle()->exists();
    }

    /** Avance confirmée (ou aucune avance due) : la boutique peut être attribuée une fois le contrat signé. */
    public function avanceReglee(): bool
    {
        return $this->avance_payee_le !== null || (int) $this->avance === 0;
    }

    /** Statut affiché : « Réservée : avance payée », « Annulée : avance non payée dans le délai »… */
    public function libelleStatut(): string
    {
        return match (true) {
            $this->statut === StatutReservationBoutique::Annulee && $this->annulation_motif === self::ANNULATION_AVANCE => __('public.boutiques.statuts.annulee_avance'),
            $this->statut === StatutReservationBoutique::Reservee && $this->avance_payee_le !== null => __('public.boutiques.statuts.avance_payee'),
            default => $this->statut->label(),
        };
    }

    /**
     * @return HasMany<DecisionAvance, $this>
     */
    public function decisionsAvance(): HasMany
    {
        return $this->hasMany(DecisionAvance::class, 'reservation_boutique_id')->latest('id');
    }

    public function fraisAPayer(): int
    {
        return 0;
    }

    public function attendPaiement(): bool
    {
        return $this->naturePaiementAttendue() !== null;
    }

    /**
     * Caution payée : boutique réservée ; date limite de paiement de l'avance figée (30 jours par défaut). Avance payée :
     * référence du paiement retenue pour l'attribution.
     */
    public function marquerPaye(): void
    {
        if ($this->naturePaiementAttendue() === Paiement::AVANCE) {
            $this->forceFill(['avance_payee_le' => now(),
                'reference_avance' => $this->paiements()->where('nature', Paiement::AVANCE)->where('statut', StatutPaiement::Confirme)->latest('id')->value('reference')])->save();

            return;
        }

        $this->forceFill(['statut' => StatutReservationBoutique::Reservee, 'payee_le' => now(), 'reservee_le' => now(),
            'avance_due_le' => now()->addSeconds(ParametresPaiements::delaiSecondes('avance'))])->save();
        $this->boutique?->changerStatut(StatutBoutique::Reservee, 'caution_payee', $this);
    }

    public function estExpire(): bool
    {
        return $this->statut === StatutReservationBoutique::Expiree;
    }

    /** Boutique remise disponible (expiration, annulation) ; historique conservé. */
    public function libererBoutique(string $motif, ?User $auteur = null): bool
    {
        $boutique = $this->boutique;
        if ($boutique === null || ! in_array($boutique->statut, [StatutBoutique::PreReservee, StatutBoutique::Reservee, StatutBoutique::Attribuee], true)) {
            return false;
        }
        $boutique->changerStatut(StatutBoutique::Disponible, $motif, $this, $auteur);

        return true;
    }

    /** @return list<MoyenPaiement> moyens ouverts, autorisés pour la location (caution, avance) et dont les plafonds couvrent le montant */
    public function canauxPaiement(): array
    {
        return $this->campagne->canaux($this->montantAPayer() + $this->fraisAPayer());
    }

    public function smsPaiementConfirme(Paiement $paiement): array
    {
        return [__($paiement->nature === Paiement::AVANCE ? 'public.boutiques.sms.avance_payee' : 'public.boutiques.sms.caution_payee'), [
            'recu' => Recu::numero($paiement),
            'reference' => $this->reference,
            'boutique' => (string) $this->boutique?->code,
            'montant' => format_fcfa($paiement->montant),
        ]];
    }

    /** @return list<array{libelle: string, montant: int, mention: string}> caution (à payer) et avance (due avant le contrat) */
    public function lignesMontants(): array
    {
        return MontantsBoutique::lignes($this->caution, $this->avance, $this->mois_avance);
    }

    /**
     * @return HasMany<Acceptation, $this>
     */
    public function acceptations(): HasMany
    {
        return $this->hasMany(Acceptation::class, 'reservation_boutique_id');
    }
}
