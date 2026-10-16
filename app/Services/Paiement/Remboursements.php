<?php

namespace App\Services\Paiement;

use App\Enums\StatutSouscription;
use App\Models\CandidatureBoutique;
use App\Models\Paiement;
use App\Models\Remboursement;
use App\Models\Souscription;
use App\Models\User;
use App\Services\Audit\Journal;
use App\Services\Validation\NotificationDecision;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Remboursements : ouverts automatiquement (souscription ou candidature annulée après paiement), suivis par le
 * CONTRÔLEUR DES PAIEMENTS qui déclare leur exécution, confirmés ou rejetés par le VALIDATEUR, jamais par l'auteur
 * de la déclaration (quatre yeux).
 */
class Remboursements
{
    public function __construct(private readonly Journal $journal, private readonly NotificationDecision $notifications) {}

    /**
     * Ouvre le remboursement d'un paiement : montant réellement payé (frais compris) pour un objet annulé, ou la somme
     * déjà reçue d'un paiement incomplet jamais complété.
     */
    public function ouvrir(Souscription|CandidatureBoutique $objet, Paiement $paiement, string $origine, ?User $acteur, ?int $montant = null): Remboursement
    {
        $remboursement = Remboursement::create([
            ...($objet instanceof Souscription ? ['souscription_id' => $objet->id] : ['candidature_boutique_id' => $objet->id]),
            'paiement_id' => $paiement->id, 'user_id' => $objet->user_id, 'montant' => $montant ?? $paiement->montant + $paiement->frais,
            'origine' => $origine, 'statut' => Remboursement::A_EFFECTUER,
        ]);
        $this->journal->ecrire($acteur, 'remboursement.ouvert', 'Remboursement', $remboursement->id, null,
            ['reference' => $remboursement->reference, 'objet' => $objet->reference, 'origine' => $origine]);

        return $remboursement;
    }

    /** Le contrôleur déclare l'exécution (mode, référence de l'opération) : le remboursement attend le VALIDATEUR. */
    public function declarerExecution(Remboursement $r, string $mode, string $reference, User $controleur): void
    {
        DB::transaction(function () use ($r, $mode, $reference, $controleur) {
            $r = Remboursement::query()->lockForUpdate()->findOrFail($r->id);
            if ($r->statut !== Remboursement::A_EFFECTUER) {
                throw ValidationException::withMessages(['reference_operation' => __('admin.remboursements.erreurs.deja_declare')]);
            }
            $r->forceFill(['statut' => Remboursement::PROPOSE, 'mode' => $mode, 'reference_operation' => $reference,
                'propose_par' => $controleur->id, 'propose_le' => now(), 'motif_rejet' => null])->save();
            $this->journal->ecrire($controleur, 'remboursement.execution_declaree', 'Remboursement', $r->id, ['statut' => Remboursement::A_EFFECTUER],
                ['statut' => Remboursement::PROPOSE, 'mode' => $mode, 'reference_operation' => $reference]);
        });
    }

    public function confirmer(Remboursement $r, User $validateur): void
    {
        $this->decider($r, $validateur, true, null);
    }

    public function rejeter(Remboursement $r, User $validateur, string $motif): void
    {
        $this->decider($r, $validateur, false, $motif);
    }

    private function decider(Remboursement $r, User $validateur, bool $confirme, ?string $motif): void
    {
        DB::transaction(function () use ($r, $validateur, $confirme, $motif) {
            $r = Remboursement::query()->lockForUpdate()->findOrFail($r->id);
            if ($r->statut !== Remboursement::PROPOSE) {
                throw ValidationException::withMessages(['motif' => __('admin.a_valider.erreurs.deja_traite')]);
            }
            if ($r->propose_par === $validateur->id) {
                throw ValidationException::withMessages(['motif' => __('admin.a_valider.erreurs.auto_validation')]);
            }

            $r->forceFill($confirme
                ? ['statut' => Remboursement::EFFECTUE, 'valide_par' => $validateur->id, 'valide_le' => now()]
                : ['statut' => Remboursement::A_EFFECTUER, 'motif_rejet' => $motif])->save();
            if ($confirme && $r->souscription_id) {
                Souscription::query()->whereKey($r->souscription_id)->where('statut', StatutSouscription::Annulee)->update(['statut' => StatutSouscription::Remboursee]);
            }
            $this->journal->ecrire($validateur, $confirme ? 'remboursement.confirme' : 'remboursement.rejete', 'Remboursement', $r->id,
                ['statut' => Remboursement::PROPOSE], ['statut' => $r->statut, 'motif' => $motif]);

            DB::afterCommit(fn () => $this->notifications->envoyer($r->propose_par, __('admin.a_valider.elements.remboursement'), $r->reference,
                $confirme ? 'confirmer' : 'rejeter', $motif, route('admin.remboursements')));
        });
    }
}
