<?php

namespace App\Console\Commands;

use App\Enums\StatutKyc;
use App\Models\User;
use App\Models\VerificationKyc;
use App\Notifications\PiecesAMettreAJour as Notification;
use App\Services\Audit\Journal;
use App\Services\Kyc\VerificationIdentite;
use Illuminate\Console\Command;

/**
 * Dossiers d'identité anciens qui ne suivent plus les règles des pièces (17/10/2026) : pièce autre que CNIB, CIB-AES ou
 * passeport, numéro ou date d'expiration absents, pièce manquante, et pour une personne morale RCCM, IFU ou justificatif
 * de pouvoir absents. Rien n'est supprimé : le dossier est marqué « pièces à mettre à jour », le titulaire notifié, l'ADMIN
 * le voit dans « À traiter » et dans les vérifications d'identité ; pas de carte tant que le dossier n'est pas complété.
 * Les dossiers d'une identité reconnue douteuse (annulation confirmée) ne sont pas touchés.
 * --simuler : compte seulement, sans rien modifier.
 */
class PiecesAMettreAJour extends Command
{
    protected $signature = 'raaga:kyc-pieces-a-mettre-a-jour {--simuler : Compte les dossiers concernés sans rien modifier}';

    protected $description = 'Marque « pièces à mettre à jour » les dossiers d’identité qui ne suivent plus les règles des pièces, et notifie leurs titulaires.';

    public function handle(VerificationIdentite $verification, Journal $journal): int
    {
        $douteux = VerificationKyc::query()->select('user_id')->where('statut', VerificationKyc::ANNULATION_CONFIRMEE);
        $concernes = User::query()->whereIn('statut_kyc', [StatutKyc::EnAttente, StatutKyc::Valide, StatutKyc::Rejete])
            ->whereNotIn('id', $douteux)->orderBy('id')->get()
            ->reject(fn (User $u) => $verification->suitLesRegles($u));

        $simuler = (bool) $this->option('simuler');
        foreach ($concernes as $user) {
            if ($simuler) {
                continue;
            }
            $avant = $user->statut_kyc;
            $user->forceFill(['statut_kyc' => StatutKyc::AMettreAJour, 'kyc_maj_demandee_le' => now()])->save();
            $journal->ecrire(null, 'kyc.pieces_a_mettre_a_jour', 'User', $user->id, ['statut_kyc' => $avant->value], ['statut_kyc' => StatutKyc::AMettreAJour->value]);
            $user->notify(new Notification);
        }

        $this->info(($simuler ? 'Simulation : ' : '').$concernes->count().' dossier(s) d’identité '.($simuler ? 'à marquer' : 'marqué(s)').' « pièces à mettre à jour ».');

        return self::SUCCESS;
    }
}
