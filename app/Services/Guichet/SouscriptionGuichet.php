<?php

namespace App\Services\Guichet;

use App\Enums\CanalSouscription;
use App\Enums\FamilleMoyenPaiement;
use App\Enums\Role;
use App\Enums\StatutKyc;
use App\Enums\StatutSouscription;
use App\Enums\TypeDocumentKyc;
use App\Models\MoyenPaiement;
use App\Models\Paiement;
use App\Models\Souscription;
use App\Models\User;
use App\Services\Auth\OtpException;
use App\Services\Auth\OtpService;
use App\Services\Compte\CreationCompte;
use App\Services\Kyc\DossiersIdentite;
use App\Services\Kyc\VerificationIdentite;
use App\Services\Paiement\ServicePaiement;
use App\Services\Souscriptions\PreparationSouscription;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Souscription d'actions au guichet, pour une personne présente, par l'AGENT DE GUICHET :
 *  1. présence et accord : code SMS envoyé au numéro de la personne, qu'elle communique à l'agent ;
 *  2. compte : créé par l'agent s'il n'existe pas (mêmes règles que l'inscription en ligne) ;
 *  3. identité : pièces photographiées au guichet et identité validée sur place par l'agent ;
 *  4. souscription : mêmes contrôles qu'en ligne (tranche ouverte, minimum, plafond, engagements, justificatif) ;
 *  5. encaissement : espèces, mobile money réglé devant l'agent ou carte au terminal, parmi les moyens retenus par la
 *     tranche ; définitif dès son enregistrement, il entre dans la caisse du jour de l'agent.
 */
class SouscriptionGuichet
{
    public const OBJET_OTP = 'guichet';

    /** Familles de moyens de paiement encaissables au guichet. */
    public const FAMILLES = [FamilleMoyenPaiement::Guichet, FamilleMoyenPaiement::MobileMoney, FamilleMoyenPaiement::CarteBancaire];

    public function __construct(
        private readonly OtpService $otp,
        private readonly CreationCompte $creation,
        private readonly VerificationIdentite $verification,
        private readonly DossiersIdentite $dossiers,
        private readonly PreparationSouscription $preparation,
        private readonly ServicePaiement $paiements,
    ) {}

    /** Compte citoyen du numéro, null s'il n'existe pas ; un numéro tenu par un compte du back-office est refusé. */
    public function personne(string $telephone): ?User
    {
        $user = User::query()->where('telephone', $telephone)->first();
        if ($user && ! $user->hasRole(Role::Citoyen)) {
            throw ValidationException::withMessages(['telephone' => __('admin.guichet.souscription.erreurs.pas_citoyen')]);
        }

        return $user;
    }

    /** @throws OtpException */
    public function envoyerCode(string $telephone): void
    {
        $this->otp->envoyer($telephone, self::OBJET_OTP);
    }

    /** @throws OtpException */
    public function verifierCode(string $telephone, string $code): bool
    {
        return $this->otp->verifier($telephone, self::OBJET_OTP, $code);
    }

    public function creerCompte(User $agent, string $telephone, array $donnees, ?string $ip, ?string $userAgent): User
    {
        if (User::query()->where('telephone', $telephone)->exists()) {
            throw ValidationException::withMessages(['telephone' => __('admin.guichet.souscription.erreurs.compte_existe')]);
        }

        return $this->creation->creer($donnees, $telephone, $ip, $userAgent, $agent);
    }

    /**
     * Pièces photographiées au guichet (celles qui manquent au dossier) puis identité validée sur place par l'agent, qui a
     * comparé la pièce présentée avec la personne.
     *
     * @param  array<string, UploadedFile>  $fichiers
     */
    public function validerIdentite(User $agent, User $citoyen, ?TypeDocumentKyc $piece, array $fichiers, array $infos): void
    {
        if ($citoyen->statut_kyc === StatutKyc::Valide) {
            return;
        }

        DB::transaction(function () use ($agent, $citoyen, $piece, $fichiers, $infos) {
            if ($this->verification->aFournir($citoyen, $piece) !== [] || $citoyen->statut_kyc !== StatutKyc::EnAttente) {
                $this->verification->deposer($citoyen, $piece, $fichiers, $infos);
                $citoyen->refresh();
            }
            $this->dossiers->conforme($citoyen, $agent, __('admin.guichet.souscription.motif_identite'), $infos['piece_expire_le'] ?? null);
        });
    }

    /** @param  array<int, string>  $engagements */
    public function souscrire(User $agent, User $citoyen, int $nbActions, array $engagements, ?UploadedFile $justificatif, string $ip, string $userAgent): Souscription
    {
        $tranche = PreparationSouscription::trancheOuverte()
            ?? throw ValidationException::withMessages(['nb_actions' => __('admin.guichet.souscription.erreurs.aucune_tranche')]);
        if ($citoyen->statut_kyc !== StatutKyc::Valide) {
            throw ValidationException::withMessages(['nb_actions' => __('admin.guichet.souscription.erreurs.identite')]);
        }

        // Aucun moyen encaissable au guichet pour ce montant : rien n'est réservé.
        return DB::transaction(function () use ($agent, $citoyen, $tranche, $nbActions, $engagements, $justificatif, $ip, $userAgent) {
            $souscription = $this->preparation->reserver($tranche, $citoyen, $nbActions, $engagements, $justificatif, $ip, $userAgent, $agent);
            if ($this->moyens($souscription) === []) {
                throw ValidationException::withMessages(['nb_actions' => __('admin.guichet.souscription.erreurs.aucun_moyen')]);
            }

            return $souscription;
        });
    }

    /** @return list<MoyenPaiement> moyens retenus par la tranche, autorisés pour ce montant et encaissables au guichet */
    public function moyens(Souscription $souscription): array
    {
        // Espèces d'abord, puis mobile money et carte, dans l'ordre du référentiel.
        return collect($souscription->loadMissing('tranche')->canauxPaiement())
            ->filter(fn (MoyenPaiement $m) => in_array($m->famille, self::FAMILLES, true))
            ->sortBy(fn (MoyenPaiement $m) => array_search($m->famille, self::FAMILLES, true))->values()->all();
    }

    /** Une référence est exigée pour le mobile money et la carte (transaction de l'opérateur, ticket du terminal). */
    public static function referenceExigee(MoyenPaiement $moyen): bool
    {
        return $moyen->famille !== FamilleMoyenPaiement::Guichet;
    }

    public function encaisser(User $agent, Souscription $souscription, string $code, ?string $reference): Paiement
    {
        if ($souscription->canal !== CanalSouscription::Guichet || $souscription->statut !== StatutSouscription::Reservee) {
            throw ValidationException::withMessages(['moyen' => __('admin.guichet.souscription.erreurs.non_encaissable')]);
        }
        $moyen = collect($this->moyens($souscription))->firstWhere('code', $code)
            ?? throw ValidationException::withMessages(['moyen' => __('admin.guichet.souscription.erreurs.moyen')]);
        $reference = trim((string) $reference) ?: null;
        if (self::referenceExigee($moyen) && ! $reference) {
            throw ValidationException::withMessages(['reference' => __('admin.guichet.souscription.erreurs.reference')]);
        }
        if ($reference && Paiement::query()->where('reference_fournisseur', $reference)->whereNotNull('agent_id')->exists()) {
            throw ValidationException::withMessages(['reference' => __('admin.guichet.souscription.erreurs.reference_utilisee')]);
        }

        return $this->paiements->encaisserAuGuichet($souscription, $moyen, $agent, $reference);
    }
}
