<?php

namespace App\Services\Souscriptions;

use App\Enums\CanalSouscription;
use App\Enums\DocumentAccepte;
use App\Enums\PublicAutorise;
use App\Enums\StatutDocumentKyc;
use App\Enums\StatutRevue;
use App\Enums\StatutSouscription;
use App\Enums\TypeDocumentKyc;
use App\Enums\TypeFraisSouscription;
use App\Models\Acceptation;
use App\Models\KycDocument;
use App\Models\Souscription;
use App\Models\Tranche;
use App\Models\User;
use App\Services\Audit\Journal;
use App\Support\Phase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Souscription en ligne d'un citoyen : calculs à partir des paramètres confirmés de la tranche ouverte
 * (prix, frais, plafond, seuils), puis réservation (statut RÉSERVÉE) avec les quatre acceptations horodatées.
 */
class PreparationSouscription
{
    /** Engagements obligatoires, dans l'ordre d'affichage. */
    public const ENGAGEMENTS = [
        DocumentAccepte::AucunDroitBoutique,
        DocumentAccepte::RendementNonGaranti,
        DocumentAccepte::ReglementTranche,
        DocumentAccepte::OrigineLiciteFonds,
    ];

    /** Statuts qui comptent dans le cumul d'un souscripteur. */
    private const STATUTS_ACTIFS = [StatutSouscription::Reservee, StatutSouscription::Payee, StatutSouscription::Allouee];

    public function __construct(private readonly Journal $journal) {}

    public static function trancheOuverte(): ?Tranche
    {
        return Phase::trancheOuverte();
    }

    public static function frais(Tranche $tranche, int $montant): int
    {
        return match ($tranche->frais_souscription_type) {
            TypeFraisSouscription::Pourcentage => intdiv($montant * (int) $tranche->frais_souscription_valeur + 5_000, 10_000),
            TypeFraisSouscription::Fixe => (int) $tranche->frais_souscription_valeur,
            default => 0,
        };
    }

    /** Montant (hors frais) et actions déjà engagés par le citoyen dans la tranche. */
    public function dejaEngage(Tranche $tranche, User $user): array
    {
        $actives = $tranche->souscriptions()->where('user_id', $user->id)->whereIn('statut', self::STATUTS_ACTIFS);

        return ['montant' => (int) (clone $actives)->sum('montant'), 'actions' => (int) (clone $actives)->sum('nb_actions_demandees'), 'nombre' => (clone $actives)->count()];
    }

    public function justificatifFourni(User $user): bool
    {
        return $user->kycDocuments()->where('type', TypeDocumentKyc::JustifFonds)
            ->whereIn('statut', [StatutDocumentKyc::EnAttente, StatutDocumentKyc::Valide])->exists();
    }

    public function publicAutorise(Tranche $tranche, User $user): bool
    {
        $public = ($user->pays_residence ?: 'BF') === 'BF' ? PublicAutorise::ResidentBf : PublicAutorise::Diaspora;

        return in_array($public->value, (array) $tranche->publics_autorises, true);
    }

    /**
     * @param  array<int, string>  $engagements  valeurs DocumentAccepte cochées
     *
     * @throws ValidationException
     */
    public function reserver(Tranche $tranche, User $user, int $nbActions, array $engagements, ?UploadedFile $justificatif, string $ip, string $userAgent): Souscription
    {
        return DB::transaction(function () use ($tranche, $user, $nbActions, $engagements, $justificatif, $ip, $userAgent) {
            // Verrou sur le compte : deux envois simultanés ne peuvent pas contourner le plafond.
            User::query()->whereKey($user->id)->lockForUpdate()->first();

            $this->controler($tranche, $user, $nbActions, $engagements, $justificatif);

            $montant = $nbActions * $tranche->valeur_unitaire;
            $cumul = $this->dejaEngage($tranche, $user)['montant'] + $montant;

            if ($justificatif) {
                $this->deposerJustificatif($user, $justificatif);
            }

            $souscription = Souscription::create([
                'reference' => $this->reference(),
                'user_id' => $user->id,
                'tranche_id' => $tranche->id,
                'nb_actions_demandees' => $nbActions,
                'valeur_unitaire' => $tranche->valeur_unitaire,
                'montant' => $montant,
                'frais_souscription' => self::frais($tranche, $montant),
                'canal' => CanalSouscription::Web,
                'statut' => StatutSouscription::Reservee,
                'revue_statut' => $tranche->seuil_revue_conformite && $cumul > $tranche->seuil_revue_conformite ? StatutRevue::EnAttente : null,
                'expire_le' => now()->addSeconds($tranche->delaiPaiementSecondes()),
            ]);

            foreach (self::ENGAGEMENTS as $document) {
                Acceptation::create([
                    'user_id' => $user->id,
                    'souscription_id' => $souscription->id,
                    'document' => $document,
                    'version_document' => $document === DocumentAccepte::ReglementTranche ? (string) $tranche->version : config('raaga.inscription.version_conditions'),
                    'accepte_le' => now(),
                    'ip' => $ip,
                    'user_agent' => substr($userAgent, 0, 500),
                ]);
            }

            $this->journal->ecrire($user, 'souscription.reservee', 'Souscription', $souscription->id, null, [
                'reference' => $souscription->reference, 'tranche' => $tranche->id, 'actions' => $nbActions, 'montant' => $montant,
            ]);

            return $souscription;
        });
    }

    /** @throws ValidationException */
    private function controler(Tranche $tranche, User $user, int $nbActions, array $engagements, ?UploadedFile $justificatif): void
    {
        $erreur = fn (string $champ, string $cle, array $params = []) => throw ValidationException::withMessages([$champ => __('public.souscription.erreurs.'.$cle, $params)]);

        if (! $user->kycValide()) {
            $erreur('identite', 'kyc');
        }
        if (! $this->publicAutorise($tranche, $user)) {
            $erreur('nb_actions', 'public');
        }

        $deja = $this->dejaEngage($tranche, $user);
        if (! $tranche->souscriptions_multiples && $deja['nombre'] > 0) {
            $erreur('nb_actions', 'multiple');
        }
        if ($nbActions < max(1, (int) $tranche->min_actions_par_souscription)) {
            $erreur('nb_actions', 'minimum', ['n' => format_nombre(max(1, (int) $tranche->min_actions_par_souscription))]);
        }
        if ($tranche->plafond_actions_par_personne && $tranche->plafond_actions_par_personne < $deja['actions'] + $nbActions) {
            $erreur('nb_actions', 'plafond', ['n' => format_nombre($tranche->plafond_actions_par_personne), 'reste' => format_nombre(max(0, $tranche->plafond_actions_par_personne - $deja['actions']))]);
        }

        $manquants = array_diff(array_map(fn (DocumentAccepte $d) => $d->value, self::ENGAGEMENTS), $engagements);
        if ($manquants) {
            $erreur('engagements', 'engagements');
        }

        $cumul = $deja['montant'] + $nbActions * $tranche->valeur_unitaire;
        if ($tranche->seuil_kyc_renforce && $cumul > $tranche->seuil_kyc_renforce && ! $justificatif && ! $this->justificatifFourni($user)) {
            $erreur('justificatif', 'justificatif', ['seuil' => format_fcfa($tranche->seuil_kyc_renforce)]);
        }
    }

    private function deposerJustificatif(User $user, UploadedFile $fichier): void
    {
        // Disque privé : jamais servi directement, consultable par le VALIDATEUR uniquement.
        $chemin = $fichier->store('kyc/'.$user->id, 'local');

        KycDocument::create([
            'user_id' => $user->id,
            'type' => TypeDocumentKyc::JustifFonds,
            'chemin_fichier' => $chemin,
            'nom_original' => Str::limit($fichier->getClientOriginalName(), 200, ''),
            'mime' => $fichier->getMimeType(),
            'taille' => $fichier->getSize(),
            'empreinte_sha256' => hash_file('sha256', $fichier->getRealPath()),
            'statut' => StatutDocumentKyc::EnAttente,
            'televerse_par' => $user->id,
        ]);
    }

    /** Référence lisible et unique : « RAA-B-7K3M9Q2X ». */
    private function reference(): string
    {
        // Sans 0/O ni 1/I/L, faciles à confondre au téléphone.
        do {
            $reference = 'RAA-B-'.strtr(Str::upper(Str::random(8)), ['0' => '7', 'O' => 'P', 'I' => 'J', 'L' => 'M', '1' => '3']);
        } while (Souscription::query()->where('reference', $reference)->exists());

        return $reference;
    }
}
