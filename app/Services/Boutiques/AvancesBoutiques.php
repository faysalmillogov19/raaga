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
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Avances des boutiques réservées traitées au cas par cas. Date limite dépassée sans avance confirmée : le dossier passe
 * « avance en retard » et le citoyen est notifié, sans aucune action automatique (la boutique reste réservée, l'avance
 * se paie encore). Le RÉGULATEUR propose une décision motivée : prolonger (nouvelle date limite), annuler (boutique
 * disponible, remboursement de la caution moins une retenue de 0 à 100 %) ou attendre (note, le dossier reste dans la
 * liste). Prolonger et annuler sont confirmés ou rejetés par le VALIDATEUR, jamais par l'auteur de la proposition.
 */
class AvancesBoutiques
{
    public function __construct(private readonly Journal $journal, private readonly Remboursements $remboursements, private readonly NotificationDecision $notifications) {}

    /**
     * Tâche planifiée : dossiers dont la date limite de l'avance est dépassée, sans avance confirmée ni preuve en
     * contrôle, marqués « avance en retard » (une seule fois) ; le citoyen est notifié.
     */
    public function marquerRetards(): int
    {
        $n = 0;
        ReservationBoutique::query()->where('statut', StatutReservationBoutique::Reservee)->whereNull('avance_payee_le')->where('avance', '>', 0)
            ->whereNull('avance_retard_le')->where('avance_due_le', '<', now())
            ->whereDoesntHave('paiements', fn ($p) => $p->enControle())->with('user')->orderBy('id')
            ->each(function (ReservationBoutique $r) use (&$n) {
                $r->forceFill(['avance_retard_le' => now()])->save();
                $this->journal->ecrire(null, 'reservation_boutique.avance_en_retard', 'ReservationBoutique', $r->id, null,
                    ['reference' => $r->reference, 'date_limite' => $r->avance_due_le->toAtomString()]);
                $r->user->notify(new AvanceBoutiqueCitoyen($r, 'retard'));
                $n++;
            });

        return $n;
    }

    /** Proposition du RÉGULATEUR sur un dossier en retard (motif obligatoire) ; « attendre » est seulement noté. */
    public function proposer(ReservationBoutique $r, string $decision, string $motif, ?CarbonInterface $nouvelleDate, ?int $retenuePct, User $regulateur): DecisionAvance
    {
        return DB::transaction(function () use ($r, $decision, $motif, $nouvelleDate, $retenuePct, $regulateur) {
            $r = ReservationBoutique::query()->lockForUpdate()->findOrFail($r->id);
            $erreur = fn (string $champ, string $cle) => throw ValidationException::withMessages([$champ => __('admin.avances.erreurs.'.$cle)]);
            if (! $r->avanceEnRetard()) {
                $erreur('decision', 'pas_en_retard');
            }
            if ($decision !== DecisionAvance::ATTENDRE && $r->decisionsAvance()->where('statut', DecisionAvance::PROPOSEE)->exists()) {
                $erreur('decision', 'en_cours');
            }
            if ($decision === DecisionAvance::PROLONGER && ($nouvelleDate === null || ! $nouvelleDate->isFuture())) {
                $erreur('nouvelle_date', 'date');
            }
            if ($decision === DecisionAvance::ANNULER && ($retenuePct === null || $retenuePct < 0 || $retenuePct > 100)) {
                $erreur('retenue_pct', 'retenue');
            }

            $d = $r->decisionsAvance()->create([
                'decision' => $decision, 'motif' => $motif,
                'nouvelle_date' => $decision === DecisionAvance::PROLONGER ? $nouvelleDate : null,
                'retenue_pct' => $decision === DecisionAvance::ANNULER ? $retenuePct : null,
                'statut' => $decision === DecisionAvance::ATTENDRE ? DecisionAvance::NOTEE : DecisionAvance::PROPOSEE,
                'propose_par' => $regulateur->id, 'propose_le' => now(),
            ]);
            $this->journal->ecrire($regulateur, 'avance.decision_proposee', 'ReservationBoutique', $r->id, null,
                ['reference' => $r->reference, 'decision' => $decision, 'motif' => $motif, 'nouvelle_date' => $d->nouvelle_date?->toAtomString(), 'retenue_pct' => $d->retenue_pct]);

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
            if ($d->statut !== DecisionAvance::PROPOSEE) {
                throw ValidationException::withMessages(['motif' => __('admin.a_valider.erreurs.deja_traite')]);
            }
            if ($d->propose_par === $validateur->id) {
                throw ValidationException::withMessages(['motif' => __('admin.a_valider.erreurs.auto_validation')]);
            }
            $r = ReservationBoutique::query()->lockForUpdate()->findOrFail($d->reservation_boutique_id);
            // Avance payée entre-temps : la proposition est sans objet.
            if ($confirmee && ! $r->avanceEnRetard()) {
                $d->forceFill(['statut' => DecisionAvance::CADUQUE])->save();
                throw ValidationException::withMessages(['motif' => __('admin.avances.erreurs.plus_en_retard')]);
            }

            $remboursement = null;
            if ($confirmee && $d->decision === DecisionAvance::PROLONGER) {
                $r->forceFill(['avance_due_le' => $d->nouvelle_date, 'avance_retard_le' => null])->save();
            } elseif ($confirmee) {
                // Annulation : boutique disponible, paiements en attente clos, caution remboursée moins la retenue.
                $r->forceFill(['statut' => StatutReservationBoutique::Annulee, 'avance_retard_le' => null])->save();
                $r->libererBoutique('annulation_avance', $validateur);
                $r->paiements()->where('statut', StatutPaiement::EnAttente)->update(['statut' => StatutPaiement::Annule]);
                $caution = $r->paiements()->where('nature', Paiement::CAUTION)->where('statut', StatutPaiement::Confirme)->latest('id')->first();
                $montant = $d->montantRembourse();
                if ($caution && $montant > 0) {
                    $remboursement = $this->remboursements->ouvrir($r, $caution, Remboursement::ANNULATION_AVANCE, $validateur, $montant);
                }
            }

            $d->forceFill(['statut' => $confirmee ? DecisionAvance::CONFIRMEE : DecisionAvance::REJETEE, 'decide_par' => $validateur->id,
                'decide_le' => now(), 'motif_decision' => $motif, 'remboursement_id' => $remboursement?->id])->save();
            $this->journal->ecrire($validateur, $confirmee ? 'avance.decision_confirmee' : 'avance.decision_rejetee', 'ReservationBoutique', $r->id, null,
                ['reference' => $r->reference, 'decision' => $d->decision, 'motif' => $motif, 'remboursement' => $remboursement?->reference]);

            DB::afterCommit(function () use ($d, $r, $confirmee, $motif) {
                $this->notifications->envoyer($d->propose_par, __('admin.a_valider.elements.avance'), $r->reference, $confirmee ? 'confirmer' : 'rejeter', $motif, route('admin.avances'));
                // Le citoyen est notifié de la décision confirmée.
                if ($confirmee) {
                    $r->user->notify(new AvanceBoutiqueCitoyen($r->fresh(), $d->decision === DecisionAvance::PROLONGER ? 'prolongee' : 'annulee', $d));
                }
            });
        });
    }
}
