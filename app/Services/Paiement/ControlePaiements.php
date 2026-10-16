<?php

namespace App\Services\Paiement;

use App\Enums\StatutCandidatureBoutique;
use App\Enums\StatutPaiement;
use App\Enums\StatutSouscription;
use App\Models\CandidatureBoutique;
use App\Models\CorrectionPaiement;
use App\Models\Paiement;
use App\Models\ParametresPaiements;
use App\Models\Souscription;
use App\Models\User;
use App\Notifications\ControlePaiementCitoyen;
use App\Services\Audit\Journal;
use App\Services\Validation\NotificationDecision;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Contrôle des paiements par le CONTRÔLEUR DES PAIEMENTS. Un paiement confirmé automatiquement par un opérateur n'a
 * besoin de personne ; un paiement manuel (virement, versement, espèces sur présentation du reçu de caisse) est marqué
 * reçu, incomplet ou rejeté, avec un motif. La correction d'un paiement déjà confirmé est proposée par le contrôleur
 * et confirmée par le VALIDATEUR, jamais par son auteur. Aucun montant, aucune grille, aucun moyen de paiement ne change.
 */
class ControlePaiements
{
    public function __construct(
        private readonly ServicePaiement $paiements,
        private readonly Journal $journal,
        private readonly NotificationDecision $notifications,
    ) {}

    /**
     * @param  string  $controle  Paiement::RECU | INCOMPLET | REJETE
     * @param  int|null  $montantRecu  somme réellement reçue, pour un paiement incomplet (remboursée si le complément n'arrive pas)
     */
    public function marquer(Paiement $paiement, string $controle, ?string $motif, ?string $reference, User $controleur, ?int $montantRecu = null): Paiement
    {
        $erreur = fn (string $cle) => throw ValidationException::withMessages(['controle' => __('admin.paiements.erreurs.'.$cle)]);
        if (! $paiement->estManuel()) {
            $erreur('non_manuel');
        }
        if ($paiement->statut !== StatutPaiement::EnAttente) {
            $erreur('deja_traite');
        }
        if (in_array($controle, [Paiement::RECU, Paiement::INCOMPLET], true) && ! $paiement->objet()->attendPaiement()) {
            $erreur('objet_clos');
        }
        if ($controle === Paiement::INCOMPLET && ($montantRecu === null || $montantRecu < 1 || $montantRecu >= $paiement->montant + $paiement->frais)) {
            throw ValidationException::withMessages(['controle_montant_recu' => __('admin.paiements.erreurs.montant_recu')]);
        }

        $avant = $paiement->only(['statut', 'controle']);
        match ($controle) {
            Paiement::RECU => $this->paiements->confirmer($paiement->reference, true, $controleur),
            Paiement::REJETE => $this->paiements->confirmer($paiement->reference, false, $controleur),
            default => null,
        };

        $paiement->refresh()->forceFill(['controle' => $controle, 'controle_motif' => $motif, 'controle_reference' => $reference,
            'controle_par' => $controleur->id, 'controle_le' => now(),
            ...($controle === Paiement::INCOMPLET ? ['controle_montant_recu' => $montantRecu] : [])])->save();
        // Incomplet : délai de complément (48 h par défaut, paramétré par le RÉGULATEUR), à partir de cette décision,
        // même si le délai initial est dépassé.
        if ($controle === Paiement::INCOMPLET) {
            $paiement->objet()->forceFill(['expire_le' => now()->addHours(ParametresPaiements::delaiComplementHeures())])->save();
        }
        $this->journal->ecrire($controleur, 'paiement.controle', 'Paiement', $paiement->id, $avant,
            ['statut' => $paiement->statut->value, 'controle' => $controle, 'motif' => $motif, 'reference' => $reference, 'montant_recu' => $montantRecu]);

        // Preuve rejetée : le délai restant reprend ; s'il est déjà dépassé, la boutique ou la souscription est libérée.
        $objet = $paiement->objet()->refresh();
        $libere = $controle === Paiement::REJETE && $this->paiements->expirerSiEchu($objet);
        $objet->user->notify(new ControlePaiementCitoyen($paiement, $objet->refresh(), $libere));

        return $paiement;
    }

    /** Correction d'un paiement confirmé (finalement non reçu) : proposée, en attente du VALIDATEUR. */
    public function proposerCorrection(Paiement $paiement, string $motif, User $controleur): CorrectionPaiement
    {
        if ($paiement->statut !== StatutPaiement::Confirme) {
            throw ValidationException::withMessages(['motif' => __('admin.paiements.erreurs.correction_non_confirme')]);
        }
        if (! $this->objetPaye($paiement->objet())) {
            throw ValidationException::withMessages(['motif' => __('admin.paiements.erreurs.correction_trop_tard')]);
        }
        if ($paiement->corrections()->where('statut', CorrectionPaiement::PROPOSEE)->exists()) {
            throw ValidationException::withMessages(['motif' => __('admin.paiements.erreurs.correction_en_cours')]);
        }

        $correction = $paiement->corrections()->create(['motif' => $motif, 'statut' => CorrectionPaiement::PROPOSEE,
            'propose_par' => $controleur->id, 'propose_le' => now()]);
        $this->journal->ecrire($controleur, 'paiement.correction_proposee', 'Paiement', $paiement->id, null, ['motif' => $motif]);

        return $correction;
    }

    /**
     * Correction confirmée : le paiement est annulé et l'objet redevient en attente de paiement, avec un nouveau délai
     * (celui de la tranche ou de la campagne) ; le citoyen pourra payer de nouveau et sera relancé.
     */
    public function confirmerCorrection(CorrectionPaiement $correction, User $validateur): void
    {
        $this->decider($correction, $validateur, true, null);
    }

    public function rejeterCorrection(CorrectionPaiement $correction, User $validateur, string $motif): void
    {
        $this->decider($correction, $validateur, false, $motif);
    }

    private function decider(CorrectionPaiement $correction, User $validateur, bool $confirmee, ?string $motif): void
    {
        DB::transaction(function () use ($correction, $validateur, $confirmee, $motif) {
            $correction = CorrectionPaiement::query()->lockForUpdate()->findOrFail($correction->id);
            if ($correction->statut !== CorrectionPaiement::PROPOSEE) {
                throw ValidationException::withMessages(['motif' => __('admin.a_valider.erreurs.deja_traite')]);
            }
            if ($correction->propose_par === $validateur->id) {
                throw ValidationException::withMessages(['motif' => __('admin.a_valider.erreurs.auto_validation')]);
            }

            $paiement = Paiement::query()->lockForUpdate()->findOrFail($correction->paiement_id);
            if ($confirmee) {
                $objet = $paiement->objet();
                if (! $this->objetPaye($objet)) {
                    throw ValidationException::withMessages(['motif' => __('admin.paiements.erreurs.correction_trop_tard')]);
                }
                $paiement->forceFill(['statut' => StatutPaiement::Annule,
                    'metadonnees' => [...(array) $paiement->metadonnees, 'correction' => $correction->id]])->save();
                $delai = $objet instanceof Souscription ? (int) $objet->tranche->delai_paiement_heures : (int) $objet->campagne->delai_paiement_heures;
                $objet->forceFill(['statut' => $objet instanceof Souscription ? StatutSouscription::Reservee : StatutCandidatureBoutique::Reservee,
                    'payee_le' => null, 'expire_le' => now()->addHours(max(1, $delai))])->save();
            }

            $correction->forceFill(['statut' => $confirmee ? CorrectionPaiement::CONFIRMEE : CorrectionPaiement::REJETEE,
                'decide_par' => $validateur->id, 'decide_le' => now(), 'motif_decision' => $motif])->save();
            $this->journal->ecrire($validateur, $confirmee ? 'paiement.correction_confirmee' : 'paiement.correction_rejetee', 'Paiement', $paiement->id,
                null, ['correction' => $correction->id, 'motif' => $motif]);

            DB::afterCommit(fn () => $this->notifications->envoyer($correction->propose_par, __('admin.a_valider.elements.correction'), $paiement->reference,
                $confirmee ? 'confirmer' : 'rejeter', $motif, route('admin.paiements')));
        });
    }

    /** Objet encore au stade « payé » (ni alloué, ni retenu au tirage, ni annulé) : seul cas corrigeable. */
    private function objetPaye(Souscription|CandidatureBoutique $objet): bool
    {
        return $objet instanceof Souscription ? $objet->statut === StatutSouscription::Payee : $objet->statut === StatutCandidatureBoutique::Payee;
    }
}
