<?php

namespace App\Services\Boutiques;

use App\Enums\StatutKyc;
use App\Enums\StatutPaiement;
use App\Enums\StatutReservationBoutique;
use App\Models\Paiement;
use App\Models\ParametresPaiements;
use App\Models\Remboursement;
use App\Models\ReservationBoutique;
use App\Notifications\IdentiteAttributaireCitoyen;
use App\Services\Audit\Journal;
use App\Services\Paiement\Remboursements;
use App\Services\Sms\EnvoiSms;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Identité des attributaires de boutiques (tâche planifiée). L'attributaire paie et fait vérifier son identité en
 * parallèle ; l'attribution définitive exige une identité validée. Identité rejetée, à mettre à jour ou jamais soumise :
 *  - il est prévenu (SMS et « Mon espace ») et dispose du délai commun de correction (« Délais de paiement », 48 h par
 *    défaut, le même que pour les actions) ;
 *  - identité renvoyée (en attente de vérification) ou validée dans le délai : le délai est levé ;
 *  - délai dépassé sans correction : réservation annulée, boutique au suivant de sa liste d'attente ; caution payée
 *    remboursée moins la retenue décidée (RÉGULATEUR propose, VALIDATEUR décide), avance payée remboursée en entier.
 * Le délai est gelé tant qu'une preuve de paiement attend son contrôle. L'identité douteuse (fraude) suit son propre
 * circuit : annulation confirmée par le VALIDATEUR, immédiate (DossiersIdentite).
 */
class IdentitesAttributaires
{
    /** Identités à corriger : rejetée, à mettre à jour, jamais soumise. */
    public const A_CORRIGER = [StatutKyc::Rejete, StatutKyc::AMettreAJour, StatutKyc::NonSoumis];

    private const EN_COURS = [StatutReservationBoutique::PreReservee, StatutReservationBoutique::Reservee];

    public function __construct(private readonly Journal $journal, private readonly Remboursements $remboursements) {}

    /** @return list<string> */
    private static function aCorriger(): array
    {
        return array_map(fn (StatutKyc $s) => $s->value, self::A_CORRIGER);
    }

    /** @return array{signalees: int, levees: int, annulees: int} */
    public function surveiller(): array
    {
        $bilan = ['signalees' => 0, 'levees' => 0, 'annulees' => 0];

        // Identité à corriger : délai fixé, attributaire prévenu.
        ReservationBoutique::query()->whereIn('statut', self::EN_COURS)->whereNull('kyc_a_corriger_avant')
            ->whereHas('user', fn ($u) => $u->whereIn('statut_kyc', self::aCorriger())->orWhereNull('statut_kyc'))
            ->with(['user', 'campagne', 'boutique'])->get()
            ->each(function (ReservationBoutique $r) use (&$bilan) {
                $r->forceFill(['kyc_a_corriger_avant' => now()->addSeconds(ParametresPaiements::delaiSecondes('kyc'))])->save();
                $this->journal->ecrire(null, 'reservation_boutique.identite_a_corriger', 'ReservationBoutique', $r->id, null,
                    ['reference' => $r->reference, 'statut_kyc' => $r->user->statut_kyc?->value, 'avant' => $r->kyc_a_corriger_avant->toAtomString()]);
                $this->prevenir($r, 'a_corriger');
                $bilan['signalees']++;
            });

        // Identité renvoyée ou validée : délai levé.
        $bilan['levees'] = ReservationBoutique::query()->whereIn('statut', self::EN_COURS)->whereNotNull('kyc_a_corriger_avant')
            ->whereHas('user', fn ($u) => $u->whereNotIn('statut_kyc', self::aCorriger()))
            ->update(['kyc_a_corriger_avant' => null, 'updated_at' => now()]);

        // Délai dépassé sans correction (hors preuve de paiement en contrôle) : annulation.
        ReservationBoutique::query()->whereIn('statut', self::EN_COURS)->where('kyc_a_corriger_avant', '<=', now())
            ->whereDoesntHave('paiements', fn ($p) => $p->enControle())->pluck('id')
            ->each(function (int $id) use (&$bilan) {
                $bilan['annulees'] += (int) $this->annulerUne($id);
            });

        return $bilan;
    }

    private function annulerUne(int $id): bool
    {
        $r = DB::transaction(function () use ($id) {
            $r = ReservationBoutique::query()->lockForUpdate()->with('user')->find($id);
            if (! $r || ! in_array($r->statut, self::EN_COURS, true) || ! $r->kyc_a_corriger_avant?->isPast()
                || ! in_array($r->user->statut_kyc, [...self::A_CORRIGER, null], true) || $r->paiements()->enControle()->exists()) {
                return null;
            }

            $avant = $r->statut->value;
            $r->forceFill(['statut' => StatutReservationBoutique::Annulee, 'annulation_motif' => ReservationBoutique::ANNULATION_KYC])->save();
            $r->paiements()->where('statut', StatutPaiement::EnAttente)->update(['statut' => StatutPaiement::Annule]);
            $caution = $r->paiements()->where('nature', Paiement::CAUTION)->where('statut', StatutPaiement::Confirme)->latest('id')->first();
            if ($caution) {
                $this->remboursements->ouvrir($r, $caution, Remboursement::KYC_NON_CORRIGE, null, (int) $r->caution, Remboursement::EN_ATTENTE_RETENUE);
            }
            $avance = $r->paiements()->where('nature', Paiement::AVANCE)->where('statut', StatutPaiement::Confirme)->latest('id')->first();
            if ($avance) {
                $this->remboursements->ouvrir($r, $avance, Remboursement::KYC_NON_CORRIGE, null);
            }
            $r->libererBoutique('identite_non_corrigee');
            $this->journal->ecrire(null, 'reservation_boutique.annulee_identite_non_corrigee', 'ReservationBoutique', $r->id, ['statut' => $avant], [
                'statut' => 'ANNULEE', 'reference' => $r->reference, 'date_limite' => $r->kyc_a_corriger_avant->toAtomString(),
                'caution_a_rembourser' => (bool) $caution, 'avance_a_rembourser' => (bool) $avance,
            ]);

            return $r;
        });

        if ($r === null) {
            return false;
        }
        $this->prevenir($r, 'annulee');
        // Boutique libérée : le suivant de sa liste d'attente est retenu.
        app(ListesAttente::class)->apresLiberation($r);

        return true;
    }

    /** @param  string  $evenement  a_corriger | annulee */
    private function prevenir(ReservationBoutique $r, string $evenement): void
    {
        $r->user->notify(new IdentiteAttributaireCitoyen($r, $evenement));
        if (! $r->user->telephone) {
            return;
        }
        try {
            app(EnvoiSms::class)->envoyer((string) $r->user->telephone, __('public.boutiques.sms.identite_'.$evenement), [
                'boutique' => (string) $r->boutique?->code, 'date' => $r->kyc_a_corriger_avant?->translatedFormat('j F Y à H\hi') ?? '',
            ]);
        } catch (Throwable $e) {
            Log::warning('SMS d’identité d’attributaire non envoyé', ['reservation' => $r->id, 'erreur' => $e->getMessage()]);
        }
    }
}
