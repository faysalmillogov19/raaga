<?php

namespace App\Services\Kyc;

use App\Enums\StatutBoutique;
use App\Enums\StatutCandidatureBoutique;
use App\Enums\StatutDocumentKyc;
use App\Enums\StatutKyc;
use App\Enums\StatutPaiement;
use App\Enums\StatutSouscription;
use App\Models\Boutique;
use App\Models\CandidatureBoutique;
use App\Models\KycDocument;
use App\Models\Remboursement;
use App\Models\Souscription;
use App\Models\User;
use App\Models\VerificationKyc;
use App\Notifications\AnnulationIdentite;
use App\Services\Audit\Journal;
use App\Services\Paiement\Remboursements;
use App\Services\Validation\NotificationDecision;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Vérifications d'identité : l'ADMIN voit la pièce et marque le dossier « conforme » (identité validée) ou
 * « douteux » (proposition d'annulation, avec un motif). Le VALIDATEUR confirme ou rejette l'annulation, jamais
 * l'auteur de la proposition. Annulation confirmée : souscriptions et candidatures en cours annulées, boutique
 * éventuelle libérée, remboursement ouvert pour ce qui était payé, citoyen notifié. L'ADMIN ne voit aucun montant.
 */
class DossiersIdentite
{
    public function __construct(
        private readonly VerificationIdentite $verification,
        private readonly Remboursements $remboursements,
        private readonly Journal $journal,
        private readonly NotificationDecision $notifications,
    ) {}

    /** Proposition d'annulation en attente du VALIDATEUR pour ce citoyen, ou null. */
    public static function propositionEnAttente(User $citoyen): ?VerificationKyc
    {
        return VerificationKyc::query()->where('user_id', $citoyen->id)->where('statut', VerificationKyc::ANNULATION_PROPOSEE)->latest('id')->first();
    }

    public function conforme(User $citoyen, User $admin, ?string $motif): VerificationKyc
    {
        $this->examinable($citoyen, [StatutKyc::EnAttente]);

        return DB::transaction(function () use ($citoyen, $admin, $motif) {
            $this->verification->dossier($citoyen)->filter()->each(fn (KycDocument $d) => $d->forceFill([
                'statut' => StatutDocumentKyc::Valide, 'commentaire' => null, 'valide_par' => $admin->id, 'valide_le' => now(),
            ])->save());
            $citoyen->forceFill(['statut_kyc' => StatutKyc::Valide, 'niveau_kyc' => max(1, (int) $citoyen->niveau_kyc)])->save();
            $v = VerificationKyc::create(['user_id' => $citoyen->id, 'decision' => VerificationKyc::CONFORME, 'motif' => $motif,
                'decide_par' => $admin->id, 'statut' => VerificationKyc::CONFORME]);
            $this->journal->ecrire($admin, 'kyc.conforme', 'User', $citoyen->id, null, ['motif' => $motif]);

            return $v;
        });
    }

    public function douteux(User $citoyen, User $admin, string $motif): VerificationKyc
    {
        $this->examinable($citoyen, [StatutKyc::EnAttente, StatutKyc::Valide]);

        $v = VerificationKyc::create(['user_id' => $citoyen->id, 'decision' => VerificationKyc::DOUTEUX, 'motif' => $motif,
            'decide_par' => $admin->id, 'statut' => VerificationKyc::ANNULATION_PROPOSEE]);
        $this->journal->ecrire($admin, 'kyc.douteux', 'User', $citoyen->id, null, ['motif' => $motif, 'proposition' => $v->id]);

        return $v;
    }

    public function confirmerAnnulation(VerificationKyc $v, User $validateur): void
    {
        $this->decider($v, $validateur, true, null);
    }

    public function rejeterAnnulation(VerificationKyc $v, User $validateur, string $motif): void
    {
        $this->decider($v, $validateur, false, $motif);
    }

    private function decider(VerificationKyc $v, User $validateur, bool $confirmee, ?string $motif): void
    {
        $bilan = DB::transaction(function () use ($v, $validateur, $confirmee, $motif) {
            $v = VerificationKyc::query()->lockForUpdate()->findOrFail($v->id);
            if ($v->statut !== VerificationKyc::ANNULATION_PROPOSEE) {
                throw ValidationException::withMessages(['motif' => __('admin.a_valider.erreurs.deja_traite')]);
            }
            if ($v->decide_par === $validateur->id) {
                throw ValidationException::withMessages(['motif' => __('admin.a_valider.erreurs.auto_validation')]);
            }

            $bilan = $confirmee ? $this->annuler($v->user, $v->motif, $validateur) : null;
            $v->forceFill(['statut' => $confirmee ? VerificationKyc::ANNULATION_CONFIRMEE : VerificationKyc::ANNULATION_REJETEE,
                'valide_par' => $validateur->id, 'valide_le' => now(), 'motif_validateur' => $motif])->save();
            $this->journal->ecrire($validateur, $confirmee ? 'kyc.annulation_confirmee' : 'kyc.annulation_rejetee', 'User', $v->user_id,
                null, ['proposition' => $v->id, 'motif' => $motif, ...($bilan ?? [])]);

            return $bilan;
        });

        if ($confirmee) {
            $v->user->notify(new AnnulationIdentite($bilan));
        }
        $this->notifications->envoyer($v->decide_par, __('admin.a_valider.elements.annulation'), $v->user->name,
            $confirmee ? 'confirmer' : 'rejeter', $motif, route('admin.kyc.dossier', $v->user_id));
    }

    /**
     * Annulation pour identité douteuse : souscriptions réservées ou payées annulées, candidatures en cours annulées
     * (boutique attribuée redevenue disponible), paiements en attente annulés, remboursement ouvert pour chaque
     * paiement confirmé. Les souscriptions déjà allouées (actions inscrites au registre) ne sont pas touchées.
     *
     * @return array{souscriptions: list<string>, candidatures: list<string>, boutiques: int, remboursements: int}
     */
    private function annuler(User $citoyen, ?string $motif, User $validateur): array
    {
        $bilan = ['souscriptions' => [], 'candidatures' => [], 'boutiques' => 0, 'remboursements' => 0];

        $this->verification->dossier($citoyen)->filter()->each(fn (KycDocument $d) => $d->forceFill([
            'statut' => StatutDocumentKyc::Rejete, 'commentaire' => $motif, 'valide_par' => $validateur->id, 'valide_le' => now(),
        ])->save());
        $citoyen->forceFill(['statut_kyc' => StatutKyc::Rejete])->save();

        $souscriptions = Souscription::query()->where('user_id', $citoyen->id)->whereIn('statut', [StatutSouscription::Reservee, StatutSouscription::Payee])->lockForUpdate()->get();
        foreach ($souscriptions as $s) {
            $bilan['remboursements'] += $this->clore($s, $validateur);
            $s->forceFill(['statut' => StatutSouscription::Annulee])->save();
            $bilan['souscriptions'][] = $s->reference;
        }

        $candidatures = CandidatureBoutique::query()->where('user_id', $citoyen->id)
            ->whereIn('statut', [StatutCandidatureBoutique::Reservee, StatutCandidatureBoutique::Payee, StatutCandidatureBoutique::Retenue])->lockForUpdate()->get();
        foreach ($candidatures as $c) {
            $bilan['remboursements'] += $this->clore($c, $validateur);
            if ($c->boutique_id && Boutique::query()->whereKey($c->boutique_id)->where('statut', StatutBoutique::Attribuee)->update(['statut' => StatutBoutique::Disponible])) {
                $bilan['boutiques']++;
            }
            $c->forceFill(['statut' => StatutCandidatureBoutique::Annulee])->save();
            $bilan['candidatures'][] = $c->reference;
        }

        return $bilan;
    }

    /** Paiements en attente annulés ; remboursement ouvert pour le paiement confirmé. Rend le nombre de remboursements ouverts. */
    private function clore(Souscription|CandidatureBoutique $objet, User $validateur): int
    {
        $objet->paiements()->where('statut', StatutPaiement::EnAttente)->update(['statut' => StatutPaiement::Annule]);
        $confirme = $objet->paiements()->where('statut', StatutPaiement::Confirme)->latest('id')->first();
        if ($confirme === null) {
            return 0;
        }
        $this->remboursements->ouvrir($objet, $confirme, Remboursement::ANNULATION_IDENTITE, $validateur);

        return 1;
    }

    /** @param  list<StatutKyc>  $statuts */
    private function examinable(User $citoyen, array $statuts): void
    {
        if (! in_array($citoyen->statut_kyc, $statuts, true)) {
            throw ValidationException::withMessages(['motif' => __('admin.kyc.erreurs.non_examinable')]);
        }
        if (self::propositionEnAttente($citoyen)) {
            throw ValidationException::withMessages(['motif' => __('admin.kyc.erreurs.proposition_en_cours')]);
        }
    }
}
