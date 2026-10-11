<?php

namespace App\Services\Paiement;

use App\Contracts\PaymentProvider;
use App\Enums\CanalPaiement;
use App\Enums\StatutPaiement;
use App\Enums\StatutSouscription;
use App\Models\Paiement;
use App\Models\Souscription;
use App\Models\User;
use App\Services\Audit\Journal;
use App\Services\Sms\EnvoiSms;
use App\Support\Recu;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Paiement d'une souscription RÉSERVÉE : demande (EN_ATTENTE), confirmation idempotente sur la
 * référence du paiement (RÉSERVÉE → PAYÉE), échec, expiration des réservations non payées.
 */
class ServicePaiement
{
    public function __construct(private readonly Journal $journal, private readonly EnvoiSms $sms) {}

    /** Paiement en attente de la souscription (reprise après rechargement ou coupure). */
    public static function enAttente(Souscription $souscription): ?Paiement
    {
        return $souscription->paiements()->where('statut', StatutPaiement::EnAttente)->latest('id')->first();
    }

    public function initier(Souscription $souscription, CanalPaiement $canal, ?string $telephone, PaymentProvider $fournisseur, User $acteur): Paiement
    {
        $paiement = DB::transaction(function () use ($souscription, $canal, $telephone, $acteur) {
            $souscription = Souscription::query()->lockForUpdate()->findOrFail($souscription->id);

            // Une seule demande en cours : la précédente est remplacée (changement de canal).
            $souscription->paiements()->where('statut', StatutPaiement::EnAttente)->update(['statut' => StatutPaiement::Annule]);

            $paiement = $souscription->paiements()->create([
                'canal' => $canal,
                'montant' => $souscription->montant,
                'frais' => $souscription->frais_souscription,
                'reference' => 'PAY-'.Str::upper(Str::ulid()),
                'statut' => StatutPaiement::EnAttente,
                'metadonnees' => array_filter(['telephone' => $telephone]),
            ]);

            $this->journal->ecrire($acteur, 'paiement.initie', 'Paiement', $paiement->id, null, [
                'souscription' => $souscription->reference, 'canal' => $canal->value, 'reference' => $paiement->reference,
            ]);

            return $paiement;
        });

        $fournisseur->initier($paiement);

        return $paiement;
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

            $souscription = Souscription::query()->lockForUpdate()->findOrFail($paiement->souscription_id);

            // Réservation expirée ou déjà payée par un autre paiement : la demande est close sans effet.
            if ($succes && $souscription->statut !== StatutSouscription::Reservee) {
                $paiement->forceFill(['statut' => StatutPaiement::Annule, 'metadonnees' => [...(array) $paiement->metadonnees, 'motif' => 'souscription_'.strtolower($souscription->statut->value)]])->save();

                return $paiement;
            }

            $paiement->forceFill([
                'statut' => $succes ? StatutPaiement::Confirme : StatutPaiement::Echoue,
                'date_confirmation' => $succes ? now() : null,
            ])->save();

            if ($succes) {
                $souscription->forceFill(['statut' => StatutSouscription::Payee, 'payee_le' => now()])->save();
                DB::afterCommit(fn () => $this->smsConfirmation($souscription, $paiement));
            }

            $this->journal->ecrire($acteur, $succes ? 'paiement.confirme' : 'paiement.echoue', 'Paiement', $paiement->id, null, [
                'souscription' => $souscription->reference, 'reference' => $paiement->reference,
            ]);

            return $paiement;
        });
    }

    /** SMS de confirmation avec le numéro de reçu ; un échec d'envoi ne remet jamais le paiement en cause. */
    private function smsConfirmation(Souscription $souscription, Paiement $paiement): void
    {
        try {
            $this->sms->envoyer($souscription->user->telephone, __('public.recu.sms'), [
                'recu' => Recu::numero($paiement),
                'reference' => $souscription->reference,
                'actions' => format_nombre($souscription->nb_actions_demandees),
                'montant' => format_fcfa($paiement->montant + $paiement->frais),
            ]);
        } catch (Throwable $e) {
            Log::warning('SMS de confirmation de paiement non envoyé', ['paiement' => $paiement->id, 'erreur' => $e->getMessage()]);
        }
    }

    /** Expire les réservations dont le délai de paiement est dépassé. Renvoie le nombre expiré. */
    public function expirerReservations(): int
    {
        $nombre = 0;

        Souscription::query()
            ->where('statut', StatutSouscription::Reservee)
            ->where('expire_le', '<=', now())
            ->pluck('id')
            ->each(function (int $id) use (&$nombre) {
                DB::transaction(function () use ($id, &$nombre) {
                    $souscription = Souscription::query()->lockForUpdate()->find($id);

                    if (! $souscription || $souscription->statut !== StatutSouscription::Reservee || $souscription->expire_le->isFuture()) {
                        return;
                    }

                    $souscription->forceFill(['statut' => StatutSouscription::Expiree])->save();
                    $souscription->paiements()->where('statut', StatutPaiement::EnAttente)->update(['statut' => StatutPaiement::Annule]);
                    $this->journal->ecrire(null, 'souscription.expiree', 'Souscription', $souscription->id, null, ['reference' => $souscription->reference]);
                    $nombre++;
                });
            });

        return $nombre;
    }
}
