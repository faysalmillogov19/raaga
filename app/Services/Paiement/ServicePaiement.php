<?php

namespace App\Services\Paiement;

use App\Contracts\Payable;
use App\Contracts\PaymentProvider;
use App\Enums\StatutCandidatureBoutique;
use App\Enums\StatutPaiement;
use App\Enums\StatutSouscription;
use App\Models\CandidatureBoutique;
use App\Models\MoyenPaiement;
use App\Models\Paiement;
use App\Models\Souscription;
use App\Models\User;
use App\Services\Audit\Journal;
use App\Services\Sms\EnvoiSms;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Paiement d'un objet RÉSERVÉ (souscription d'actions ou caution d'une candidature aux boutiques) :
 * demande (EN_ATTENTE), confirmation idempotente sur la référence du paiement (RÉSERVÉE → PAYÉE),
 * échec, expiration des réservations non payées dans leur délai.
 */
class ServicePaiement
{
    public function __construct(private readonly Journal $journal, private readonly EnvoiSms $sms) {}

    /** Paiement en attente (reprise après rechargement ou coupure). */
    public static function enAttente(Payable $objet): ?Paiement
    {
        return $objet->paiements()->where('statut', StatutPaiement::EnAttente)->latest('id')->first();
    }

    public function initier(Payable&Model $objet, MoyenPaiement $canal, ?string $telephone, PaymentProvider $fournisseur, User $acteur): Paiement
    {
        $paiement = $this->creer($objet, $canal, $telephone, $acteur);
        $fournisseur->initier($paiement);

        return $paiement;
    }

    /**
     * Paiement manuel (virement, versement, espèces) : aucun opérateur. Le citoyen reçoit le montant exact, le compte
     * de destination et une référence de paiement unique, puis joint sa preuve ; le CONTRÔLEUR DES PAIEMENTS tranche.
     */
    public function initierManuel(Payable&Model $objet, MoyenPaiement $canal, User $acteur): Paiement
    {
        return $this->creer($objet, $canal, null, $acteur, Paiement::nouvelleReferenceVersement());
    }

    private function creer(Payable&Model $objet, MoyenPaiement $canal, ?string $telephone, User $acteur, ?string $referenceVersement = null): Paiement
    {
        return DB::transaction(function () use ($objet, $canal, $telephone, $acteur, $referenceVersement) {
            /** @var Payable&Model $objet */
            $objet = $objet::query()->lockForUpdate()->findOrFail($objet->getKey());

            // Une seule demande en cours : la précédente est remplacée (changement de canal).
            $objet->paiements()->where('statut', StatutPaiement::EnAttente)->update(['statut' => StatutPaiement::Annule]);

            $paiement = $objet->paiements()->create([
                'canal' => $canal,
                'compte_destination' => $objet->compteDestination(),
                'montant' => $objet->montantAPayer(),
                'frais' => $objet->fraisAPayer(),
                'reference' => 'PAY-'.Str::upper(Str::ulid()),
                'statut' => StatutPaiement::EnAttente,
                'metadonnees' => array_filter(['telephone' => $telephone]),
                'reference_versement' => $referenceVersement,
            ]);

            $this->journal->ecrire($acteur, 'paiement.initie', 'Paiement', $paiement->id, null, [
                'objet' => $objet->reference, 'canal' => $canal->code, 'compte' => $objet->compteDestination()->value, 'reference' => $paiement->reference,
            ]);

            return $paiement;
        });
    }

    /**
     * Retour de l'opérateur (ou du simulateur). Idempotent : une référence déjà traitée n'est jamais
     * confirmée une seconde fois ; le résultat renvoyé est alors l'état existant.
     */
    public function confirmer(string $reference, bool $succes, ?User $acteur = null): Paiement
    {
        return DB::transaction(function () use ($reference, $succes, $acteur) {
            $paiement = Paiement::query()->where('reference', $reference)->lockForUpdate()->firstOrFail();

            if ($paiement->statut !== StatutPaiement::EnAttente) {
                return $paiement;
            }

            $classe = $paiement->souscription_id ? Souscription::class : CandidatureBoutique::class;
            /** @var Payable&Model $objet */
            $objet = $classe::query()->lockForUpdate()->findOrFail($paiement->souscription_id ?? $paiement->candidature_boutique_id);

            // Réservation expirée ou déjà payée par un autre paiement : la demande est close sans effet.
            if ($succes && ! $objet->attendPaiement()) {
                $paiement->forceFill(['statut' => StatutPaiement::Annule, 'metadonnees' => [...(array) $paiement->metadonnees, 'motif' => 'objet_'.strtolower($objet->statut->value)]])->save();

                return $paiement;
            }

            $paiement->forceFill([
                'statut' => $succes ? StatutPaiement::Confirme : StatutPaiement::Echoue,
                'date_confirmation' => $succes ? now() : null,
            ])->save();

            if ($succes) {
                $objet->marquerPaye();
                DB::afterCommit(fn () => $this->smsConfirmation($objet, $paiement));
            }

            $this->journal->ecrire($acteur, $succes ? 'paiement.confirme' : 'paiement.echoue', 'Paiement', $paiement->id, null, [
                'objet' => $objet->reference, 'reference' => $paiement->reference,
            ]);

            return $paiement;
        });
    }

    /** SMS de confirmation avec le numéro de reçu ; un échec d'envoi ne remet jamais le paiement en cause. */
    private function smsConfirmation(Payable&Model $objet, Paiement $paiement): void
    {
        try {
            [$modele, $variables] = $objet->smsPaiementConfirme($paiement);
            $this->sms->envoyer($objet->user->telephone, $modele, $variables);
        } catch (Throwable $e) {
            Log::warning('SMS de confirmation de paiement non envoyé', ['paiement' => $paiement->id, 'erreur' => $e->getMessage()]);
        }
    }

    /** Expire les souscriptions et candidatures réservées dont le délai de paiement est dépassé. */
    public function expirerReservations(): int
    {
        return $this->expirer(Souscription::class, StatutSouscription::Reservee, StatutSouscription::Expiree, 'souscription.expiree')
            + $this->expirer(CandidatureBoutique::class, StatutCandidatureBoutique::Reservee, StatutCandidatureBoutique::Expiree, 'candidature_boutique.expiree');
    }

    /**
     * Expire un objet précis si son délai est dépassé et qu'aucune preuve n'attend de contrôle (après le rejet d'une
     * preuve déposée trop tard : la boutique ou la souscription est libérée).
     */
    public function expirerSiEchu(Souscription|CandidatureBoutique $objet): bool
    {
        [$reservee, $expiree, $action] = $objet instanceof Souscription
            ? [StatutSouscription::Reservee, StatutSouscription::Expiree, 'souscription.expiree']
            : [StatutCandidatureBoutique::Reservee, StatutCandidatureBoutique::Expiree, 'candidature_boutique.expiree'];

        return $this->expirerUn($objet::class, $objet->id, $reservee, $expiree, $action);
    }

    /**
     * Délai gelé : un objet dont une preuve de paiement manuel attend le contrôle n'expire pas, même délai dépassé.
     *
     * @param  class-string<Model&Payable>  $classe
     */
    private function expirer(string $classe, \BackedEnum $reservee, \BackedEnum $expiree, string $action): int
    {
        $nombre = 0;

        $classe::query()->where('statut', $reservee)->where('expire_le', '<=', now())
            ->whereDoesntHave('paiements', fn ($p) => $p->enControle())->pluck('id')
            ->each(function (int $id) use ($classe, $reservee, $expiree, $action, &$nombre) {
                $nombre += (int) $this->expirerUn($classe, $id, $reservee, $expiree, $action);
            });

        return $nombre;
    }

    /** @param class-string<Model&Payable> $classe */
    private function expirerUn(string $classe, int $id, \BackedEnum $reservee, \BackedEnum $expiree, string $action): bool
    {
        return DB::transaction(function () use ($classe, $id, $reservee, $expiree, $action) {
            $objet = $classe::query()->lockForUpdate()->find($id);

            if (! $objet || $objet->statut !== $reservee || $objet->expire_le->isFuture() || $objet->paiements()->enControle()->exists()) {
                return false;
            }

            $objet->forceFill(['statut' => $expiree])->save();
            $objet->paiements()->where('statut', StatutPaiement::EnAttente)->update(['statut' => StatutPaiement::Annule]);
            $this->journal->ecrire(null, $action, class_basename($classe), $objet->id, null, ['reference' => $objet->reference]);

            return true;
        });
    }
}
