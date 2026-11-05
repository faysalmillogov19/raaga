<?php

namespace App\Services\Boutiques;

use App\Enums\StatutDocumentKyc;
use App\Enums\TypeDocumentKyc;
use App\Models\InscriptionBoutique;
use App\Models\KycDocument;
use App\Models\Recepisse;
use App\Models\User;
use App\Services\Audit\Journal;
use App\Services\Sms\EnvoiSms;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Formalisation différée (réunion du 05/11/2026, lot 2). Une personne physique peut s'inscrire sur une boutique sans
 * IFU : son dossier passe « en cours de formalisation » et elle reçoit un récépissé de préinscription Raaga. Délai
 * réglé par le SUPER_ADMIN (carte.delaiFormalisationMois, 6 mois par défaut) à compter de la première inscription sans
 * IFU ; rappels par SMS à 2 mois, 1 mois et 15 jours de l'échéance ; à l'échéance, alerte au back-office seulement (aucune
 * action sur la boutique). Formalisé : IFU saisi et attestation validée par l'ADMIN ; la carte n'est émise qu'alors.
 */
class Formalisation
{
    /** Rappels : clé => [mois, jours] avant l'échéance. */
    public const RAPPELS = ['2_mois' => [2, 0], '1_mois' => [1, 0], '15_jours' => [0, 15]];

    public function __construct(private readonly Journal $journal, private readonly EnvoiSms $sms) {}

    /** Délai de formalisation, en mois (réglage de la carte, 6 par défaut). */
    public static function delaiMois(): int
    {
        return max(1, (int) (plateforme('carte.delaiFormalisationMois') ?: 6));
    }

    /** IFU saisi et attestation IFU validée (dernière attestation déposée, pour ce numéro). */
    public static function formalise(User $user): bool
    {
        if (blank($user->ifu)) {
            return false;
        }
        $attestation = KycDocument::query()->where('user_id', $user->id)->where('type', TypeDocumentKyc::Ifu)->latest('id')->first();

        return $attestation?->statut === StatutDocumentKyc::Valide;
    }

    /** Dossier « en cours de formalisation » : délai ouvert par une inscription sans IFU, IFU pas encore validé. */
    public static function enCours(User $user): bool
    {
        return $user->formalisation_debut_le !== null && ! self::formalise($user);
    }

    /** Échéance dépassée sans formalisation. */
    public static function echue(User $user): bool
    {
        return self::enCours($user) && $user->formalisation_echeance_le?->lt(today());
    }

    /**
     * Inscription sur une boutique d'une personne physique sans IFU : délai ouvert à la première (jamais repoussé
     * ensuite), récépissé de préinscription pour cette inscription.
     */
    public function demarrer(User $user, InscriptionBoutique $inscription): Recepisse
    {
        return DB::transaction(function () use ($user, $inscription) {
            if ($user->formalisation_debut_le === null) {
                $debut = CarbonImmutable::now();
                $user->forceFill(['formalisation_debut_le' => $debut, 'formalisation_echeance_le' => $debut->addMonthsNoOverflow(self::delaiMois())->toDateString(),
                    'formalisation_rappels' => [], 'formalisation_alerte_le' => null])->save();
                $this->journal->ecrire($user, 'formalisation.demarree', 'User', $user->id, null, ['echeance' => $user->formalisation_echeance_le->toDateString()]);
            }
            $recepisse = Recepisse::query()->firstOrCreate(['inscription_boutique_id' => $inscription->id], [
                'numero' => $this->numero(), 'jeton' => Str::random(64), 'user_id' => $user->id, 'boutique_id' => $inscription->boutique_id,
                'emis_le' => now(), 'echeance_le' => $user->formalisation_echeance_le,
            ]);
            if ($recepisse->wasRecentlyCreated) {
                $this->journal->ecrire($user, 'recepisse.emis', 'Recepisse', $recepisse->id, null, ['numero' => $recepisse->numero]);
            }

            return $recepisse;
        });
    }

    /**
     * Tâche quotidienne : rappels par SMS (une fois chacun), alerte au back-office à l'échéance ; une formalisation
     * achevée clôt le délai. @return array{rappels: int, alertes: int, formalises: int}
     */
    public function echeances(): array
    {
        $bilan = ['rappels' => 0, 'alertes' => 0, 'formalises' => 0];
        $enCours = User::query()->whereNotNull('formalisation_debut_le')->whereNotNull('formalisation_echeance_le')->get();
        foreach ($enCours as $user) {
            if (self::formalise($user)) {
                continue;
            }
            $echeance = CarbonImmutable::parse($user->formalisation_echeance_le);
            $envoyes = (array) ($user->formalisation_rappels ?? []);
            foreach (self::RAPPELS as $cle => [$mois, $jours]) {
                $date = $echeance->subMonthsNoOverflow($mois)->subDays($jours);
                if (! in_array($cle, $envoyes, true) && today()->gte($date) && today()->lte($echeance)) {
                    if ($user->telephone) {
                        $this->sms->envoyer($user->telephone, __('public.formalisation.sms.rappel'), ['date' => $echeance->format('d/m/Y')]);
                    }
                    $envoyes[] = $cle;
                    $bilan['rappels']++;
                }
            }
            $modifs = ['formalisation_rappels' => $envoyes];
            if ($echeance->lt(today()) && $user->formalisation_alerte_le === null) {
                $modifs['formalisation_alerte_le'] = now();
                $this->journal->ecrire(null, 'formalisation.echue', 'User', $user->id, null, ['echeance' => $echeance->toDateString()]);
                $bilan['alertes']++;
            }
            $user->forceFill($modifs)->saveQuietly();
        }

        return $bilan;
    }

    /** Numéro propre au récépissé : RP-AAAA-000123 (suite annuelle). */
    private function numero(): string
    {
        $annee = now()->format('Y');
        $suivant = (int) Recepisse::query()->where('numero', 'like', 'RP-'.$annee.'-%')->count() + 1;

        do {
            $numero = sprintf('RP-%s-%06d', $annee, $suivant++);
        } while (Recepisse::query()->where('numero', $numero)->exists());

        return $numero;
    }
}
