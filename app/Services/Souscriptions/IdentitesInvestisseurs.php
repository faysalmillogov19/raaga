<?php

namespace App\Services\Souscriptions;

use App\Enums\StatutKyc;
use App\Enums\StatutSouscription;
use App\Models\LigneRegistre;
use App\Models\ParametresPaiements;
use App\Models\Souscription;
use App\Models\User;
use App\Notifications\IdentiteInvestisseurCitoyen;
use App\Services\Audit\Journal;
use App\Services\Boutiques\IdentitesAttributaires;
use App\Services\Sms\EnvoiSms;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Identité des investisseurs (tâche planifiée). L'achat d'actions est possible sans identité validée ; une identité
 * invalide (rejetée, à mettre à jour ou jamais soumise) est signalée automatiquement au souscripteur, qui dispose du
 * délai commun de correction (« Délais de paiement », 48 heures par défaut, le même que pour les boutiques) :
 *  - pièces renvoyées (en attente de vérification) ou identité validée dans le délai : délai levé ;
 *  - délai dépassé sans correction : ses actions payées sont gelées (attestation et carte d'actionnaire bloquées ;
 *    les dividendes restent versés) ;
 *  - identité validée ensuite : dégel automatique, souscripteur prévenu.
 */
class IdentitesInvestisseurs
{
    /** Souscriptions qui font d'un citoyen un investisseur suivi. */
    private const STATUTS = [StatutSouscription::Reservee, StatutSouscription::Payee, StatutSouscription::Allouee];

    public function __construct(private readonly Journal $journal) {}

    /** @return list<string> */
    private static function aCorriger(): array
    {
        return array_map(fn (StatutKyc $s) => $s->value, IdentitesAttributaires::A_CORRIGER);
    }

    /** Citoyens qui ont une souscription en cours, payée ou allouée, ou des actions inscrites au registre. */
    private static function investisseurs(): Builder
    {
        return User::query()->where(fn ($q) => $q
            ->whereIn('id', Souscription::query()->whereIn('statut', self::STATUTS)->select('user_id'))
            ->orWhereIn('id', LigneRegistre::query()->whereNotNull('user_id')->select('user_id')));
    }

    /** Actions payées : souscription payée ou allouée, ou actions au registre. */
    private static function detientActionsPayees(User $user): bool
    {
        return Souscription::query()->where('user_id', $user->id)->whereIn('statut', [StatutSouscription::Payee, StatutSouscription::Allouee])->exists()
            || LigneRegistre::query()->where('user_id', $user->id)->exists();
    }

    /** @return array{signales: int, corriges: int, geles: int, degeles: int} */
    public function surveiller(): array
    {
        $bilan = ['signales' => 0, 'corriges' => 0, 'geles' => 0, 'degeles' => 0];
        $invalide = fn ($u) => $u->where(fn ($q) => $q->whereIn('statut_kyc', self::aCorriger())->orWhereNull('statut_kyc'));

        // Identité invalide : délai fixé, souscripteur prévenu (une fois).
        $invalide(self::investisseurs()->whereNull('kyc_actions_a_corriger_avant')->whereNull('actions_gelees_le'))->get()
            ->each(function (User $u) use (&$bilan) {
                $u->forceFill(['kyc_actions_a_corriger_avant' => now()->addSeconds(ParametresPaiements::delaiSecondes('kyc'))])->save();
                $this->journal->ecrire(null, 'investisseur.identite_a_corriger', 'User', $u->id, null,
                    ['statut_kyc' => $u->statut_kyc?->value, 'avant' => $u->kyc_actions_a_corriger_avant->toAtomString()]);
                $this->prevenir($u, 'a_corriger');
                $bilan['signales']++;
            });

        // Pièces renvoyées ou identité validée avant le gel : délai levé.
        $bilan['corriges'] = User::query()->whereNotNull('kyc_actions_a_corriger_avant')->whereNull('actions_gelees_le')
            ->whereNotIn('statut_kyc', self::aCorriger())->update(['kyc_actions_a_corriger_avant' => null, 'updated_at' => now()]);

        // Délai dépassé, identité toujours invalide, actions payées : gel.
        $invalide(User::query()->whereNull('actions_gelees_le')->where('kyc_actions_a_corriger_avant', '<=', now()))->get()
            ->filter(fn (User $u) => self::detientActionsPayees($u))
            ->each(function (User $u) use (&$bilan) {
                $u->forceFill(['actions_gelees_le' => now()])->save();
                $this->journal->ecrire(null, 'investisseur.actions_gelees', 'User', $u->id, null,
                    ['statut_kyc' => $u->statut_kyc?->value, 'date_limite' => $u->kyc_actions_a_corriger_avant?->toAtomString()]);
                $this->prevenir($u, 'gelees');
                $bilan['geles']++;
            });

        // Identité validée : dégel automatique.
        User::query()->whereNotNull('actions_gelees_le')->where('statut_kyc', StatutKyc::Valide)->get()
            ->each(function (User $u) use (&$bilan) {
                $u->forceFill(['actions_gelees_le' => null, 'kyc_actions_a_corriger_avant' => null])->save();
                $this->journal->ecrire(null, 'investisseur.actions_degelees', 'User', $u->id, null, ['statut_kyc' => StatutKyc::Valide->value]);
                $this->prevenir($u, 'degelees');
                $bilan['degeles']++;
            });

        return $bilan;
    }

    /** @param  string  $evenement  a_corriger | gelees | degelees */
    private function prevenir(User $u, string $evenement): void
    {
        $u->notify(new IdentiteInvestisseurCitoyen($evenement, $u->kyc_actions_a_corriger_avant));
        if (! $u->telephone) {
            return;
        }
        try {
            app(EnvoiSms::class)->envoyer((string) $u->telephone, __('public.invest.identite.sms.'.$evenement), [
                'date' => $u->kyc_actions_a_corriger_avant?->translatedFormat('j F Y à H\hi') ?? '',
            ]);
        } catch (Throwable $e) {
            Log::warning('SMS d’identité d’investisseur non envoyé', ['user' => $u->id, 'erreur' => $e->getMessage()]);
        }
    }
}
