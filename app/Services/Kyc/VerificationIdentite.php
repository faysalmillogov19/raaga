<?php

namespace App\Services\Kyc;

use App\Enums\FaceDocument;
use App\Enums\Role;
use App\Enums\StatutDocumentKyc;
use App\Enums\StatutKyc;
use App\Enums\TypeDocumentKyc;
use App\Enums\TypeTitulaire;
use App\Models\KycDocument;
use App\Models\ReservationBoutique;
use App\Models\User;
use App\Services\Audit\Journal;
use App\Services\Sms\EnvoiSms;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Vérification d'identité, selon le type de titulaire :
 *  - personne physique : CNIB, CIB-AES ou passeport (rien d'autre), avec numéro et date d'expiration obligatoires,
 *    recto et verso (une page pour le passeport), selfie ;
 *  - personne morale : RCCM (numéro et document), IFU (numéro et attestation), pièce d'identité du représentant légal
 *    (mêmes règles) avec selfie, justificatif de pouvoir du représentant.
 * Pièces sur le disque privé ; renvoi des seules pièces rejetées ; dossier incomplet complété ; SMS à chaque étape.
 * Le dossier n'est conforme que si toutes les pièces sont présentes et validées.
 */
class VerificationIdentite
{
    /** Disque privé : jamais servi directement (voir les routes contrôlées des fichiers). */
    public const DISQUE = 'local';

    /** Emplacements dont le document peut être un PDF (attestations, registres, actes). */
    public const DOCUMENTS = ['rccm', 'ifu', 'pouvoir'];

    public function __construct(private readonly Journal $journal, private readonly EnvoiSms $sms) {}

    /**
     * Emplacements attendus : clé de formulaire => [type, face]. Pièce d'identité (personne physique ou représentant
     * légal), selfie, et pour une personne morale le RCCM, l'IFU et le justificatif de pouvoir.
     *
     * @return array<string, array{0: TypeDocumentKyc, 1: ?FaceDocument}>
     */
    public static function emplacements(TypeTitulaire $titulaire, TypeDocumentKyc $piece): array
    {
        return array_filter([
            'recto' => [$piece, FaceDocument::Recto],
            'verso' => $piece->rectoVerso() ? [$piece, FaceDocument::Verso] : null,
            'selfie' => [TypeDocumentKyc::Selfie, null],
            'rccm' => $titulaire === TypeTitulaire::Morale ? [TypeDocumentKyc::Rccm, null] : null,
            'ifu' => $titulaire === TypeTitulaire::Morale ? [TypeDocumentKyc::Ifu, null] : null,
            'pouvoir' => $titulaire === TypeTitulaire::Morale ? [TypeDocumentKyc::JustifPouvoir, null] : null,
        ]);
    }

    public static function titulaire(User $user): TypeTitulaire
    {
        return $user->type_titulaire ?? TypeTitulaire::Physique;
    }

    /**
     * Pièces du dossier en cours : la plus récente par emplacement.
     *
     * @return Collection<string, KycDocument>
     */
    public function dossier(User $user): Collection
    {
        $documents = $user->kycDocuments()->whereIn('type', [...TypeDocumentKyc::piecesIdentite(), TypeDocumentKyc::Rccm, TypeDocumentKyc::Ifu,
            TypeDocumentKyc::JustifPouvoir, TypeDocumentKyc::Selfie])->orderByDesc('id')->get();

        return collect(self::emplacements(self::titulaire($user), $this->typePiece($user) ?? TypeDocumentKyc::Cnib))
            ->map(fn (array $e) => $documents->first(fn (KycDocument $d) => $d->type === $e[0] && $d->face === $e[1]))
            ->filter();
    }

    /** Type de la pièce d'identité du dossier en cours (CNIB, CIB-AES ou passeport), ou null si rien n'a été déposé. */
    public function typePiece(User $user): ?TypeDocumentKyc
    {
        return $user->kycDocuments()->whereIn('type', TypeDocumentKyc::piecesIdentite())->latest('id')->first()?->type;
    }

    /** Emplacements attendus qui n'ont encore aucune pièce. @return list<string> */
    public function manquants(User $user): array
    {
        $piece = $this->typePiece($user);
        if ($piece === null) {
            return array_keys(self::emplacements(self::titulaire($user), TypeDocumentKyc::Cnib));
        }

        return array_values(array_diff(array_keys(self::emplacements(self::titulaire($user), $piece)), $this->dossier($user)->keys()->all()));
    }

    /**
     * Le dossier suit-il les règles des pièces ? Pièce d'identité acceptée avec son numéro et sa date d'expiration, toutes
     * les pièces attendues présentes, et pour une personne morale les numéros RCCM et IFU.
     */
    public function suitLesRegles(User $user): bool
    {
        $dossier = $this->dossier($user);
        $recto = $dossier->get('recto');
        if (! $recto || $this->manquants($user) !== [] || blank($recto->numero) || ! $recto->expire_le) {
            return false;
        }

        return self::titulaire($user) === TypeTitulaire::Physique || (filled($dossier->get('rccm')?->numero) && filled($dossier->get('ifu')?->numero));
    }

    /** Toutes les pièces présentes et conformes aux règles (préalable au dossier « conforme »). */
    public function complet(User $user): bool
    {
        return $this->suitLesRegles($user);
    }

    /** Premier dépôt (ou dossier à refaire) : toutes les pièces sont demandées. */
    public static function premierDepot(User $user): bool
    {
        return in_array($user->statut_kyc, [StatutKyc::NonSoumis, StatutKyc::AMettreAJour, null], true);
    }

    /**
     * Emplacements à fournir maintenant : tous au premier dépôt (ou dossier à mettre à jour), les pièces rejetées après
     * un rejet, les pièces encore manquantes d'un dossier commencé (pièce jointe à une réservation, par exemple).
     *
     * @return list<string>
     */
    public function aFournir(User $user, ?TypeDocumentKyc $choix = null): array
    {
        if (self::premierDepot($user)) {
            return array_keys(self::emplacements(self::titulaire($user), $choix ?? TypeDocumentKyc::Cnib));
        }

        return match ($user->statut_kyc) {
            StatutKyc::Rejete => array_values(array_unique([
                ...$this->dossier($user)->filter(fn (KycDocument $d) => $d->statut === StatutDocumentKyc::Rejete)->keys()->all(),
                ...$this->manquants($user),
            ])),
            StatutKyc::EnAttente => $this->manquants($user),
            default => [],
        };
    }

    /**
     * Dépôt (premier dépôt, renvoi après rejet, ou complément) des pièces attendues, avec leurs numéros.
     *
     * @param  array<string, UploadedFile>  $fichiers  recto, verso, selfie, rccm, ifu, pouvoir
     * @param  array{numero_piece?: ?string, piece_expire_le?: ?string, numero_rccm?: ?string, numero_ifu?: ?string}  $infos
     *
     * @throws ValidationException
     */
    public function deposer(User $user, ?TypeDocumentKyc $choix, array $fichiers, array $infos = []): void
    {
        $premierDepot = self::premierDepot($user);
        abort_unless($premierDepot || in_array($user->statut_kyc, [StatutKyc::Rejete, StatutKyc::EnAttente], true), 409);
        $piece = $premierDepot ? ($choix ?? throw ValidationException::withMessages(['type_piece' => __('public.verification.erreurs.type')])) : ($this->typePiece($user) ?? $choix);
        if (! in_array($piece, TypeDocumentKyc::piecesIdentite(), true)) {
            throw ValidationException::withMessages(['type_piece' => __('public.verification.erreurs.type')]);
        }

        $attendus = array_keys(self::emplacements(self::titulaire($user), $piece));
        if (! $premierDepot) {
            $attendus = array_values(array_intersect($this->aFournir($user), $attendus));
        }
        $manquants = array_diff($attendus, array_keys(array_filter($fichiers)));
        if ($manquants) {
            throw ValidationException::withMessages(array_fill_keys($manquants, __('public.verification.erreurs.manquant')));
        }

        // Numéros et date d'expiration : obligatoires pour chaque pièce déposée qui les porte.
        $exiges = array_filter([
            'numero_piece' => in_array('recto', $attendus, true),
            'piece_expire_le' => in_array('recto', $attendus, true),
            'numero_rccm' => in_array('rccm', $attendus, true),
            'numero_ifu' => in_array('ifu', $attendus, true),
        ]);
        $absents = array_filter(array_keys($exiges), fn (string $c) => blank($infos[$c] ?? null));
        if ($absents) {
            throw ValidationException::withMessages(array_combine($absents, array_map(fn ($c) => __('public.verification.erreurs.'.$c), $absents)));
        }
        $numeros = [
            'recto' => ReservationBoutique::normaliserPiece((string) ($infos['numero_piece'] ?? '')),
            'rccm' => mb_strtoupper(trim((string) ($infos['numero_rccm'] ?? ''))),
            'ifu' => ReservationBoutique::normaliserPiece((string) ($infos['numero_ifu'] ?? '')),
        ];

        $emplacements = self::emplacements(self::titulaire($user), $piece);
        $chemins = [];

        try {
            DB::transaction(function () use ($user, $attendus, $emplacements, $fichiers, &$chemins, $infos, $numeros) {
                foreach ($attendus as $cle) {
                    [$type, $face] = $emplacements[$cle];
                    $fichier = $fichiers[$cle];
                    $chemin = $fichier->storeAs('kyc/'.$user->id, Str::uuid().'.'.$fichier->extension(), self::DISQUE);
                    $chemins[] = $chemin;

                    KycDocument::create([
                        'user_id' => $user->id, 'type' => $type, 'face' => $face, 'chemin_fichier' => $chemin,
                        'nom_original' => Str::limit($fichier->getClientOriginalName(), 200, ''), 'mime' => $fichier->getMimeType(),
                        'taille' => $fichier->getSize(), 'empreinte_sha256' => hash_file('sha256', $fichier->getRealPath()),
                        'statut' => StatutDocumentKyc::EnAttente, 'televerse_par' => $user->id,
                        'numero' => ($numeros[$cle] ?? '') ?: null, 'expire_le' => $cle === 'recto' ? $infos['piece_expire_le'] : null,
                    ]);
                }

                // Identifiants contrôlés (limite de réservation) et date d'expiration de la pièce (validité de la carte).
                $user->forceFill(array_filter([
                    'numero_piece' => in_array('recto', $attendus, true) ? $numeros['recto'] : null,
                    'piece_expire_le' => in_array('recto', $attendus, true) ? $infos['piece_expire_le'] : null,
                    'rccm' => in_array('rccm', $attendus, true) ? $numeros['rccm'] : null,
                    'ifu' => in_array('ifu', $attendus, true) ? $numeros['ifu'] : null,
                ]))->forceFill(['statut_kyc' => StatutKyc::EnAttente, 'kyc_maj_demandee_le' => null,
                    'piece_avertie_le' => in_array('recto', $attendus, true) ? null : $user->piece_avertie_le])->save();
                $this->journal->ecrire($user, 'kyc.depose', 'User', $user->id, null, ['pieces' => $attendus]);
            });
        } catch (Throwable $e) {
            Storage::disk(self::DISQUE)->delete($chemins);

            throw $e;
        }

        $this->sms($user, 'soumis');
    }

    /**
     * Changement du type de titulaire (physique ou moral) : seulement en repassant par le KYC. Un dossier déjà déposé
     * est à refaire (rien n'est supprimé) ; impossible pendant l'examen d'un dossier ou après un rejet.
     *
     * @throws ValidationException
     */
    public function changerTitulaire(User $user, TypeTitulaire $type, ?string $raisonSociale, ?string $forme): void
    {
        if (! in_array($user->statut_kyc, [StatutKyc::NonSoumis, StatutKyc::AMettreAJour, StatutKyc::Valide, null], true)) {
            throw ValidationException::withMessages(['type_titulaire' => __('public.titulaires.erreurs.verrouille')]);
        }
        $avant = self::titulaire($user);
        $user->forceFill([
            'type_titulaire' => $type,
            'raison_sociale' => $type === TypeTitulaire::Morale ? trim((string) $raisonSociale) : null,
            'forme_morale' => $type === TypeTitulaire::Morale ? $forme : null,
            'ifu' => $type === TypeTitulaire::Morale ? $user->ifu : null,
            'rccm' => $type === TypeTitulaire::Morale ? $user->rccm : null,
            // Dossier déjà déposé : à refaire selon le nouveau type.
            'statut_kyc' => in_array($user->statut_kyc, [StatutKyc::NonSoumis, null], true) ? StatutKyc::NonSoumis : StatutKyc::AMettreAJour,
        ])->save();
        $this->journal->ecrire($user, 'kyc.titulaire_change', 'User', $user->id, ['type' => $avant->value], ['type' => $type->value, 'raison_sociale' => $user->raison_sociale]);
    }

    /**
     * Décision du VALIDATEUR sur une pièce. Le statut du compte suit : une pièce rejetée → dossier rejeté ;
     * toutes validées → identité validée ; sinon en attente. SMS au citoyen quand ce statut change.
     *
     * @throws AuthorizationException
     */
    public function decider(KycDocument $document, StatutDocumentKyc $decision, ?string $motif, User $validateur): void
    {
        if (! $validateur->actif || ! $validateur->hasRole(Role::Validateur)) {
            throw new AuthorizationException(__('public.verification.erreurs.validateur'));
        }
        if ($decision === StatutDocumentKyc::Rejete && blank($motif)) {
            throw ValidationException::withMessages(['motif' => __('public.verification.erreurs.motif')]);
        }

        $user = $document->user;
        $avant = $user->statut_kyc;

        DB::transaction(function () use ($document, $decision, $motif, $validateur, $user) {
            $document->forceFill([
                'statut' => $decision, 'commentaire' => $decision === StatutDocumentKyc::Rejete ? $motif : null,
                'valide_par' => $validateur->id, 'valide_le' => now(),
            ])->save();

            $pieces = $this->dossier($user);
            $statut = match (true) {
                $pieces->contains(fn (KycDocument $d) => $d->statut === StatutDocumentKyc::Rejete) => StatutKyc::Rejete,
                $this->complet($user) && $pieces->every(fn (KycDocument $d) => $d->statut === StatutDocumentKyc::Valide) => StatutKyc::Valide,
                default => StatutKyc::EnAttente,
            };

            $user->forceFill(['statut_kyc' => $statut, 'niveau_kyc' => $statut === StatutKyc::Valide ? max(1, (int) $user->niveau_kyc) : $user->niveau_kyc])->save();
            $this->journal->ecrire($validateur, 'kyc.piece_'.strtolower($decision->value), 'KycDocument', $document->id, null, ['citoyen' => $user->id, 'statut_compte' => $statut->value]);
        });

        $user->refresh();
        if ($user->statut_kyc !== $avant) {
            $this->sms($user, strtolower($user->statut_kyc->value));
        }
    }

    private function sms(User $user, string $evenement): void
    {
        if (! $user->telephone) {
            return;
        }

        try {
            $this->sms->envoyer($user->telephone, __('public.verification.sms.'.$evenement));
        } catch (Throwable $e) {
            Log::warning('SMS de vérification d’identité non envoyé', ['user' => $user->id, 'erreur' => $e->getMessage()]);
        }
    }
}
