<?php

namespace App\Models;

use App\Contracts\Payable;
use App\Enums\CompteDestination;
use App\Enums\StatutBoutique;
use App\Enums\StatutReservationBoutique;
use App\Support\MontantsBoutique;
use App\Support\Recu;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Réservation d'une boutique précise, au premier arrivé : pré-réservée à la confirmation (caution à payer dans le délai
 * de la campagne), réservée une fois la caution payée, attribuée quand l'avance est payée et le contrat signé. Montants
 * figés à la réservation depuis la grille tarifaire confirmée du site : seule la caution confirme la réservation ;
 * l'avance est due avant la signature du contrat. Une seule réservation active par boutique (index unique en base).
 */
#[Table(name: 'reservations_boutiques')]
#[Fillable([
    'reference', 'user_id', 'campagne_boutiques_id', 'boutique_id', 'caution', 'avance',
    'loyer_mensuel', 'mois_caution', 'mois_avance', 'statut', 'expire_le', 'payee_le',
    'type_piece', 'numero_piece', 'reservee_le', 'attribuee_le', 'reference_avance', 'contrat_signe_le',
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

    /** Montant de confirmation : la caution seule ; l'avance est due avant la signature du contrat. */
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
        return $this->statut === StatutReservationBoutique::PreReservee;
    }

    /** Caution payée : réservation et boutique « réservées ». */
    public function marquerPaye(): void
    {
        $this->forceFill(['statut' => StatutReservationBoutique::Reservee, 'payee_le' => now(), 'reservee_le' => now()])->save();
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

    /** @return list<MoyenPaiement> moyens ouverts, autorisés pour la caution et dont les plafonds couvrent le montant */
    public function canauxPaiement(): array
    {
        return $this->campagne->canaux($this->montantAPayer() + $this->fraisAPayer());
    }

    public function smsPaiementConfirme(Paiement $paiement): array
    {
        return [__('public.boutiques.sms.caution_payee'), [
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
