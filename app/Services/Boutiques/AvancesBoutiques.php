<?php

namespace App\Services\Boutiques;

use App\Enums\StatutPaiement;
use App\Enums\StatutReservationBoutique;
use App\Models\DecisionAvance;
use App\Models\Paiement;
use App\Models\Remboursement;
use App\Models\ReservationBoutique;
use App\Models\User;
use App\Notifications\AvanceBoutiqueCitoyen;
use App\Services\Audit\Journal;
use App\Services\Paiement\Remboursements;
use App\Services\Validation\NotificationDecision;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Avance non payée à temps : à la date limite, si l'avance n'est pas confirmée et qu'aucune preuve n'est en contrôle,
 * la réservation est annulée automatiquement (boutique disponible, citoyen notifié, historique des statuts et journal
 * d'audit). Le remboursement de la caution est ouvert « en attente de retenue » : le RÉGULATEUR propose la retenue
 * (0 à 100 %, motif obligatoire), le VALIDATEUR la confirme ou la rejette, jamais l'auteur de la proposition ; le
 * CONTRÔLEUR DES PAIEMENTS suit ensuite le remboursement. À 100 %, rien n'est versé et la décision reste enregistrée.
 */
class AvancesBoutiques
{
    public function __construct(private readonly Journal $journal, private readonly Remboursements $remboursements, private readonly NotificationDecision $notifications) {}

    /** Tâche planifiée : annule les réservations dont l'avance n'est pas payée à la date limite (hors preuve en contrôle). */
    public function annulerEchues(): int
    {
        $n = 0;
        ReservationBoutique::query()->where('statut', StatutReservationBoutique::Reservee)->whereNull('avance_payee_le')->where('avance', '>', 0)
            ->where('avance_due_le', '<=', now())->whereDoesntHave('paiements', fn ($p) => $p->enControle())->pluck('id')
            ->each(function (int $id) use (&$n) {
                $n += (int) $this->annulerUne($id);
            });

        return $n;
    }

    /** Même règle pour un dossier précis (consultation par le citoyen, demande de paiement). */
    public function annulerSiEchue(ReservationBoutique $r): bool
    {
        return $r->avanceEchue() && $this->annulerUne($r->id);
    }

    private function annulerUne(int $id): bool
    {
        $annulee = DB::transaction(function () use ($id) {
            $r = ReservationBoutique::query()->lockForUpdate()->find($id);
            if (! $r || ! $r->avanceEchue()) {
                return null;
            }

            $r->forceFill(['statut' => StatutReservationBoutique::Annulee, 'annulation_motif' => ReservationBoutique::ANNULATION_AVANCE])->save();
            $r->libererBoutique('avance_non_payee');

            // Avance partiellement reçue (paiement incomplet jamais complété) : la somme reçue est remboursée en entier.
            $incomplet = $r->paiements()->where('nature', Paiement::AVANCE)->whereIn('statut', [StatutPaiement::EnAttente, StatutPaiement::Echoue])
                ->where('controle_montant_recu', '>', 0)->whereNotIn('id', Remboursement::query()->whereNotNull('paiement_id')->select('paiement_id'))
                ->latest('id')->first();
            $r->paiements()->where('statut', StatutPaiement::EnAttente)->update(['statut' => StatutPaiement::Annule]);
            if ($incomplet) {
                $this->remboursements->ouvrir($r, $incomplet, Remboursement::COMPLEMENT_NON_RECU, null, $incomplet->controle_montant_recu);
            }

            // Caution : remboursement ouvert « en attente de retenue ».
            $caution = $r->paiements()->where('nature', Paiement::CAUTION)->where('statut', StatutPaiement::Confirme)->latest('id')->first();
            $remboursement = $caution
                ? $this->remboursements->ouvrir($r, $caution, Remboursement::ANNULATION_AVANCE, null, (int) $r->caution, Remboursement::EN_ATTENTE_RETENUE)
                : null;

            $this->journal->ecrire(null, 'reservation_boutique.annulee_avance_non_payee', 'ReservationBoutique', $r->id, ['statut' => 'RESERVEE'], [
                'statut' => 'ANNULEE', 'reference' => $r->reference, 'date_limite' => $r->avance_due_le?->toAtomString(),
                'remboursement' => $remboursement?->reference, 'incomplet' => $incomplet?->reference,
            ]);

            return $r;
        });

        if ($annulee) {
            $annulee->user->notify(new AvanceBoutiqueCitoyen($annulee, 'annulee_auto'));
            // Boutique libérée : le suivant de sa liste d'attente est retenu.
            app(ListesAttente::class)->apresLiberation($annulee);
        }

        return $annulee !== null;
    }

    /** Retenue proposée par le RÉGULATEUR sur une caution en attente de retenue (0 à 100 %, motif obligatoire). */
    public function proposerRetenue(Remboursement $remboursement, int $retenuePct, string $motif, User $regulateur): DecisionAvance
    {
        return DB::transaction(function () use ($remboursement, $retenuePct, $motif, $regulateur) {
            $rb = Remboursement::query()->lockForUpdate()->findOrFail($remboursement->id);
            if ($rb->statut !== Remboursement::EN_ATTENTE_RETENUE || ! in_array($rb->origine, Remboursement::ORIGINES_RETENUE, true)) {
                throw ValidationException::withMessages(['retenue_pct' => __('admin.avances.erreurs.pas_en_attente')]);
            }
            if (DecisionAvance::query()->where('remboursement_id', $rb->id)->where('statut', DecisionAvance::PROPOSEE)->exists()) {
                throw ValidationException::withMessages(['retenue_pct' => __('admin.avances.erreurs.en_cours')]);
            }
            if ($retenuePct < 0 || $retenuePct > 100) {
                throw ValidationException::withMessages(['retenue_pct' => __('admin.avances.erreurs.retenue')]);
            }

            $d = DecisionAvance::create([
                'reservation_boutique_id' => $rb->reservation_boutique_id, 'remboursement_id' => $rb->id, 'decision' => DecisionAvance::RETENUE,
                'retenue_pct' => $retenuePct, 'motif' => $motif, 'statut' => DecisionAvance::PROPOSEE, 'propose_par' => $regulateur->id, 'propose_le' => now(),
            ]);
            $this->journal->ecrire($regulateur, 'caution.retenue_proposee', 'Remboursement', $rb->id, null,
                ['reference' => $rb->reference, 'retenue_pct' => $retenuePct, 'motif' => $motif]);

            return $d;
        });
    }

    public function confirmer(DecisionAvance $d, User $validateur): void
    {
        $this->decider($d, $validateur, true, null);
    }

    public function rejeter(DecisionAvance $d, User $validateur, string $motif): void
    {
        $this->decider($d, $validateur, false, $motif);
    }

    private function decider(DecisionAvance $d, User $validateur, bool $confirmee, ?string $motif): void
    {
        DB::transaction(function () use ($d, $validateur, $confirmee, $motif) {
            $d = DecisionAvance::query()->lockForUpdate()->findOrFail($d->id);
            if ($d->statut !== DecisionAvance::PROPOSEE || $d->decision !== DecisionAvance::RETENUE) {
                throw ValidationException::withMessages(['motif' => __('admin.a_valider.erreurs.deja_traite')]);
            }
            if ($d->propose_par === $validateur->id) {
                throw ValidationException::withMessages(['motif' => __('admin.a_valider.erreurs.auto_validation')]);
            }
            $rb = Remboursement::query()->lockForUpdate()->findOrFail($d->remboursement_id);
            if ($rb->statut !== Remboursement::EN_ATTENTE_RETENUE) {
                throw ValidationException::withMessages(['motif' => __('admin.a_valider.erreurs.deja_traite')]);
            }

            // Confirmée : montant = caution moins la retenue ; à 100 %, rien n'est versé (décision enregistrée).
            if ($confirmee) {
                $montant = $d->montantRembourse();
                $rb->forceFill(['montant' => $montant, 'retenue_pct' => $d->retenue_pct,
                    'statut' => $montant > 0 ? Remboursement::A_EFFECTUER : Remboursement::RETENUE_TOTALE])->save();
            }
            $d->forceFill(['statut' => $confirmee ? DecisionAvance::CONFIRMEE : DecisionAvance::REJETEE, 'decide_par' => $validateur->id,
                'decide_le' => now(), 'motif_decision' => $motif])->save();
            $this->journal->ecrire($validateur, $confirmee ? 'caution.retenue_confirmee' : 'caution.retenue_rejetee', 'Remboursement', $rb->id, null,
                ['reference' => $rb->reference, 'retenue_pct' => $d->retenue_pct, 'montant' => $rb->montant, 'motif' => $motif]);

            DB::afterCommit(function () use ($d, $rb, $confirmee, $motif) {
                $this->notifications->envoyer($d->propose_par, __('admin.a_valider.elements.retenue'), $rb->reference, $confirmee ? 'confirmer' : 'rejeter', $motif, route('admin.cautions'));
                if ($confirmee) {
                    $d->reservation->user->notify(new AvanceBoutiqueCitoyen($d->reservation, 'retenue', $d));
                }
            });
        });
    }
}
