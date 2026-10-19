<?php

namespace App\Services\Boutiques;

use App\Enums\StatutBoutique;
use App\Enums\StatutInscriptionBoutique;
use App\Enums\StatutPaiement;
use App\Enums\StatutReservationBoutique;
use App\Enums\TypeDocumentKyc;
use App\Models\Boutique;
use App\Models\CampagneBoutiques;
use App\Models\InscriptionBoutique;
use App\Models\Paiement;
use App\Models\Remboursement;
use App\Models\ReservationBoutique;
use App\Models\TypeBoutique;
use App\Models\User;
use App\Notifications\ListeAttenteBoutiqueCitoyen;
use App\Services\Audit\Journal;
use App\Services\Kyc\VerificationIdentite;
use App\Services\Paiement\Remboursements;
use App\Services\Sms\EnvoiSms;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Listes d'attente des boutiques, au premier arrivé, premier servi.
 *
 *  - Un citoyen s'inscrit sur autant de boutiques qu'il veut. Sur une boutique libre, le premier inscrit en devient
 *    attributaire : la boutique est pré-réservée à son nom (caution à payer dans le délai). Sinon, il prend son rang
 *    dans la liste d'attente de la boutique ; le nombre de personnes déjà inscrites lui est montré avant de s'inscrire.
 *  - Quand l'attributaire perd la boutique sans l'obtenir (caution ou avance non payée à temps, désistement, identité
 *    annulée), le suivant de la liste qui n'est pas déjà attributaire d'une autre boutique de ce type (limite par
 *    personne de la campagne) est retenu à son tour, notifié par SMS et dans « Mon espace ».
 *  - Les inscriptions d'un attributaire sur d'autres boutiques sont gardées mais sautées ; s'il perd sa boutique,
 *    elles redeviennent actives à leur rang d'origine.
 *
 * Les inscriptions d'une campagne passent une par une (verrou sur la campagne) : l'ordre d'arrivée est celui de
 * l'enregistrement par le serveur, et l'identifiant le fixe.
 */
class ListesAttente
{
    public function __construct(
        private readonly ReservationsBoutiques $reservations,
        private readonly Journal $journal,
        private readonly Remboursements $remboursements,
    ) {}

    /** Pièce déjà fournie par le citoyen dans cette campagne (type et numéro), réutilisée pour ses autres inscriptions. */
    public static function pieceFournie(CampagneBoutiques $campagne, User $user): ?InscriptionBoutique
    {
        return InscriptionBoutique::query()->where('campagne_boutiques_id', $campagne->id)->where('user_id', $user->id)
            ->whereNotNull('numero_piece')->latest('id')->first();
    }

    /** Personnes ayant déjà manifesté leur intérêt pour la boutique : l'attributaire en cours et la liste d'attente. */
    public static function interesses(Boutique $boutique): int
    {
        return InscriptionBoutique::query()->where('boutique_id', $boutique->id)
            ->whereIn('statut', StatutInscriptionBoutique::actives())->count();
    }

    /** Inscription active du citoyen sur cette boutique, ou null. */
    public static function inscriptionDe(Boutique $boutique, User $user): ?InscriptionBoutique
    {
        return InscriptionBoutique::query()->where('boutique_id', $boutique->id)->where('user_id', $user->id)
            ->whereIn('statut', StatutInscriptionBoutique::actives())->with('reservation')->first();
    }

    /** Inscription possible sur la boutique : pas encore attribuée définitivement ni retirée. */
    public static function ouverteAuxInscriptions(Boutique $boutique): bool
    {
        return ! in_array($boutique->statut, [StatutBoutique::Attribuee, StatutBoutique::Retiree], true);
    }

    /**
     * Inscription sur une boutique. La pièce d'identité n'est demandée qu'à la première inscription de la campagne ;
     * les suivantes reprennent son type et son numéro.
     *
     * @param  list<string>  $engagements  valeurs DocumentAccepte cochées
     *
     * @throws ValidationException
     */
    public function inscrire(CampagneBoutiques $campagne, User $user, Boutique $boutique, ?TypeDocumentKyc $typePiece, ?string $numeroPiece,
        ?UploadedFile $piece, ?UploadedFile $rccm, array $engagements, string $ip, string $userAgent): InscriptionBoutique
    {
        $erreur = fn (string $cle, array $params = []) => throw ValidationException::withMessages(['boutique' => __('public.boutiques.erreurs.'.$cle, $params)]);
        if (! $user->telephone) {
            $erreur('telephone');
        }
        $deja = self::pieceFournie($campagne, $user);
        if ($deja) {
            $typePiece = TypeDocumentKyc::from($deja->type_piece);
            $numero = $deja->numero_piece;
        } else {
            $numero = ReservationBoutique::normaliserPiece((string) $numeroPiece);
            if ($typePiece === null || ! in_array($typePiece, ReservationsBoutiques::piecesPour($user), true) || mb_strlen($numero) < 5) {
                throw ValidationException::withMessages(['numero_piece' => __('public.boutiques.erreurs.piece')]);
            }
            if ($piece === null) {
                throw ValidationException::withMessages(['piece' => __('public.boutiques.erreurs.piece_fichier')]);
            }
        }
        if (array_diff(array_map(fn ($d) => $d->value, ReservationsBoutiques::ENGAGEMENTS), $engagements)) {
            throw ValidationException::withMessages(['engagements' => __('public.boutiques.erreurs.engagements')]);
        }

        $chemins = [];
        try {
            $inscription = DB::transaction(function () use ($campagne, $user, $boutique, $typePiece, $numero, $piece, $rccm, $deja, $ip, $userAgent, $erreur, &$chemins) {
                $campagne = CampagneBoutiques::query()->lockForUpdate()->findOrFail($campagne->id);
                // Réservations ouvertes, ou relance en cours de cette boutique pour ce membre de la liste générale.
                if (! $campagne->accepteReservations() && RelancesInteret::ouvertePour($boutique, $user)?->campagne_boutiques_id !== $campagne->id) {
                    $erreur('fermee');
                }
                $boutique = Boutique::query()->lockForUpdate()->with('type')->findOrFail($boutique->id);
                if (! in_array($boutique->site_id, (array) $campagne->sites) || ! in_array($boutique->type_boutique_id, (array) $campagne->types)
                    || ! $boutique->type?->attribuable || $boutique->type->code === TypeBoutique::CODE_CHAMBRE_FROIDE) {
                    $erreur('hors_campagne');
                }
                if (! self::ouverteAuxInscriptions($boutique)) {
                    $erreur('deja_attribuee');
                }
                $tarif = ReservationsBoutiques::tarifer($boutique) ?? $erreur('sans_tarif');
                if (self::inscriptionDe($boutique, $user)) {
                    $erreur('deja_inscrit');
                }

                $inscription = InscriptionBoutique::create([
                    'campagne_boutiques_id' => $campagne->id, 'boutique_id' => $boutique->id, 'user_id' => $user->id,
                    'statut' => StatutInscriptionBoutique::EnAttente, 'type_piece' => $typePiece->value, 'numero_piece' => $numero, 'inscrite_le' => now(),
                ]);
                if (! $deja) {
                    ReservationsBoutiques::deposerPiece($user, $typePiece, $numero, $piece, $rccm, $chemins);
                }

                // Boutique libre et citoyen sous la limite de ce type : il en devient l'attributaire tout de suite. Si une
                // demande concurrente l'a prise entre-temps (index unique), il reste simplement sur la liste d'attente.
                $reservation = null;
                if ($boutique->statut->reservable() && $this->eligible($inscription, $campagne, $boutique)) {
                    try {
                        $reservation = DB::transaction(fn () => $this->retenir($inscription, $campagne, $boutique, $tarif, null));
                    } catch (UniqueConstraintViolationException) {
                        $inscription->refresh();
                    }
                }
                ReservationsBoutiques::accepterEngagements($campagne, $user, $inscription->id, $reservation?->id, $ip, $userAgent);

                $this->journal->ecrire($user, 'inscription_boutique.creee', 'InscriptionBoutique', $inscription->id, null, [
                    'boutique' => $boutique->code, 'campagne' => $campagne->id, 'retenue' => $reservation !== null, 'rang' => $inscription->rang(),
                ]);

                return $inscription->refresh();
            });
        } catch (UniqueConstraintViolationException) {
            // Deux confirmations simultanées : l'index unique n'en garde qu'une.
            Storage::disk(VerificationIdentite::DISQUE)->delete($chemins);
            $erreur('deja_inscrit');
        } catch (Throwable $e) {
            Storage::disk(VerificationIdentite::DISQUE)->delete($chemins);

            throw $e;
        }

        return $inscription;
    }

    /**
     * Le citoyen peut être retenu sur cette boutique : il n'a pas atteint, dans la campagne, la limite par personne de
     * ce type (réservations en cours ou attribuées, sur son compte ou son numéro de pièce).
     */
    private function eligible(InscriptionBoutique $inscription, CampagneBoutiques $campagne, Boutique $boutique): bool
    {
        return ReservationsBoutiques::activesDuType($campagne, $boutique->type_boutique_id, $inscription->user_id, array_filter([$inscription->numero_piece]))
            < $campagne->limitePour($boutique->type_boutique_id);
    }

    /** @param array{loyer_mensuel: int, caution: int, avance: int, mois_caution: int, mois_avance: int} $tarif */
    private function retenir(InscriptionBoutique $inscription, CampagneBoutiques $campagne, Boutique $boutique, array $tarif, ?User $auteur): ReservationBoutique
    {
        $reservation = $this->reservations->preReserver($campagne, $inscription->user, $boutique, $inscription->type_piece, $inscription->numero_piece, $tarif, $auteur);
        $inscription->forceFill(['statut' => StatutInscriptionBoutique::Retenue, 'reservation_boutique_id' => $reservation->id, 'retenue_le' => now()])->save();

        return $reservation;
    }

    /**
     * Boutique redevenue libre : le premier de sa liste d'attente qui peut être retenu en devient l'attributaire
     * (pré-réservation, caution à payer dans le délai), puis il est notifié. Rend la réservation créée, ou null.
     */
    public function promouvoir(int $boutiqueId): ?ReservationBoutique
    {
        $campagneId = InscriptionBoutique::query()->file($boutiqueId)->value('campagne_boutiques_id');
        if ($campagneId === null) {
            return null;
        }

        $reservation = DB::transaction(function () use ($boutiqueId, $campagneId) {
            $campagne = CampagneBoutiques::query()->lockForUpdate()->findOrFail($campagneId);
            $boutique = Boutique::query()->lockForUpdate()->with('type')->findOrFail($boutiqueId);
            $tarif = ReservationsBoutiques::tarifer($boutique);
            if (! $boutique->statut->reservable() || $tarif === null) {
                return null;
            }
            foreach (InscriptionBoutique::query()->file($boutiqueId)->where('campagne_boutiques_id', $campagne->id)->lockForUpdate()->with('user')->get() as $inscription) {
                if (! $this->eligible($inscription, $campagne, $boutique)) {
                    continue;
                }
                $reservation = $this->retenir($inscription, $campagne, $boutique, $tarif, null);
                $this->journal->ecrire(null, 'inscription_boutique.retenue', 'InscriptionBoutique', $inscription->id, ['statut' => 'EN_ATTENTE'],
                    ['statut' => 'RETENUE', 'reservation' => $reservation->reference, 'boutique' => $boutique->code]);

                return $reservation;
            }

            return null;
        });

        if ($reservation) {
            $this->prevenir($reservation);
        }

        return $reservation;
    }

    /**
     * Toutes les boutiques libres qui ont une liste d'attente : le premier qui peut être retenu en devient
     * l'attributaire (tâche planifiée, et après chaque boutique libérée). Rend le nombre d'attributaires retenus.
     */
    public function reevaluer(?int $campagneId = null): int
    {
        $n = 0;
        $boutiques = InscriptionBoutique::query()->where('statut', StatutInscriptionBoutique::EnAttente)
            ->when($campagneId, fn ($q) => $q->where('campagne_boutiques_id', $campagneId))
            ->whereHas('boutique', fn ($b) => $b->whereIn('statut', [StatutBoutique::Disponible, StatutBoutique::EnCampagne]))
            ->distinct()->orderBy('boutique_id')->pluck('boutique_id');
        foreach ($boutiques as $id) {
            $n += (int) ($this->promouvoir((int) $id) !== null);
        }

        return $n;
    }

    /**
     * Réservation terminée sans attribution (expirée, annulée) : l'inscription retenue est close, puis la boutique
     * libérée passe au suivant de sa liste ; les autres inscriptions du citoyen redeviennent actives.
     */
    public function apresLiberation(ReservationBoutique $reservation, StatutInscriptionBoutique $statut = StatutInscriptionBoutique::Perdue, ?string $motif = null): void
    {
        InscriptionBoutique::query()->where('reservation_boutique_id', $reservation->id)->where('statut', StatutInscriptionBoutique::Retenue)
            ->update(['statut' => $statut->value, 'close_le' => now(), 'motif_cloture' => $motif ?? strtolower($reservation->statut->value), 'updated_at' => now()]);
        $this->reevaluer($reservation->campagne_boutiques_id);
    }

    /**
     * Désistement du citoyen : une inscription en attente sort de la liste ; une boutique dont il est attributaire (pas
     * encore attribuée) est libérée et passe au suivant. Caution déjà payée : remboursement ouvert « en attente de
     * retenue » (le RÉGULATEUR propose la retenue, le VALIDATEUR décide) ; avance déjà payée : remboursée en entier.
     *
     * @throws ValidationException
     */
    public function desister(InscriptionBoutique $inscription, User $user): void
    {
        $erreur = fn (string $cle) => throw ValidationException::withMessages(['desistement' => __('public.boutiques.attente.erreurs.'.$cle)]);
        if ($inscription->user_id !== $user->id) {
            $erreur('pas_titulaire');
        }

        $liberee = DB::transaction(function () use ($inscription, $user, $erreur) {
            $i = InscriptionBoutique::query()->lockForUpdate()->findOrFail($inscription->id);
            if ($i->statut === StatutInscriptionBoutique::EnAttente) {
                $i->forceFill(['statut' => StatutInscriptionBoutique::Desistee, 'close_le' => now(), 'motif_cloture' => 'desistement'])->save();
                $this->journal->ecrire($user, 'inscription_boutique.desistee', 'InscriptionBoutique', $i->id, ['statut' => 'EN_ATTENTE'], ['statut' => 'DESISTEE']);

                return null;
            }
            $r = $i->statut === StatutInscriptionBoutique::Retenue ? ReservationBoutique::query()->lockForUpdate()->find($i->reservation_boutique_id) : null;
            if (! $r || ! in_array($r->statut, [StatutReservationBoutique::PreReservee, StatutReservationBoutique::Reservee], true)) {
                $erreur('impossible');
            }
            if ($r->paiements()->enControle()->exists()) {
                $erreur('paiement_en_controle');
            }

            $avant = $r->statut->value;
            $r->forceFill(['statut' => StatutReservationBoutique::Annulee, 'annulation_motif' => ReservationBoutique::ANNULATION_DESISTEMENT])->save();
            $r->paiements()->where('statut', StatutPaiement::EnAttente)->update(['statut' => StatutPaiement::Annule]);
            $caution = $r->paiements()->where('nature', Paiement::CAUTION)->where('statut', StatutPaiement::Confirme)->latest('id')->first();
            if ($caution) {
                $this->remboursements->ouvrir($r, $caution, Remboursement::DESISTEMENT, null, (int) $r->caution, Remboursement::EN_ATTENTE_RETENUE);
            }
            $avance = $r->paiements()->where('nature', Paiement::AVANCE)->where('statut', StatutPaiement::Confirme)->latest('id')->first();
            if ($avance) {
                $this->remboursements->ouvrir($r, $avance, Remboursement::DESISTEMENT, null);
            }
            $r->libererBoutique('desistement', $user);
            $i->forceFill(['statut' => StatutInscriptionBoutique::Desistee, 'close_le' => now(), 'motif_cloture' => 'desistement'])->save();
            $this->journal->ecrire($user, 'reservation_boutique.desistee', 'ReservationBoutique', $r->id, ['statut' => $avant],
                ['statut' => 'ANNULEE', 'reference' => $r->reference, 'caution_a_rembourser' => (bool) $caution, 'avance_a_rembourser' => (bool) $avance]);

            return $r;
        });

        if ($liberee) {
            $this->reevaluer($liberee->campagne_boutiques_id);
        }
    }

    /** Nouvel attributaire prévenu : notification dans « Mon espace » (et e-mail), puis SMS. */
    private function prevenir(ReservationBoutique $reservation): void
    {
        $reservation->user->notify(new ListeAttenteBoutiqueCitoyen($reservation));
        if (! $reservation->user->telephone) {
            return;
        }
        try {
            app(EnvoiSms::class)->envoyer((string) $reservation->user->telephone, __('public.boutiques.sms.retenue'), [
                'boutique' => (string) $reservation->boutique?->code, 'montant' => format_fcfa($reservation->caution),
                'date' => $reservation->expire_le?->translatedFormat('j F Y à H\hi') ?? '',
            ]);
        } catch (Throwable $e) {
            Log::warning('SMS de liste d’attente non envoyé', ['reservation' => $reservation->id, 'erreur' => $e->getMessage()]);
        }
    }
}
