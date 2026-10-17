<?php

namespace App\Services\Kyc;

use App\Enums\FaceDocument;
use App\Enums\Role;
use App\Enums\StatutDocumentKyc;
use App\Enums\StatutKyc;
use App\Enums\TypeDocumentKyc;
use App\Models\KycDocument;
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
 * Vérification d'identité d'un citoyen : dépôt des pièces (CNIB recto + verso, ou passeport, + selfie)
 * sur le disque privé, renvoi des seules pièces rejetées, décision du VALIDATEUR, SMS à chaque étape.
 */
class VerificationIdentite
{
    /** Disque privé : jamais servi directement (voir les routes contrôlées des fichiers). */
    public const DISQUE = 'local';

    public function __construct(private readonly Journal $journal, private readonly EnvoiSms $sms) {}

    /** Pièces d'identité acceptées : CNIB, passeport, ou NIF (avec le RCCM pour une entreprise). */
    public const PIECES = [TypeDocumentKyc::Cnib, TypeDocumentKyc::Passeport, TypeDocumentKyc::Nif];

    /** Emplacements facultatifs (jamais exigés) : le RCCM d'une entreprise. */
    public const FACULTATIFS = ['rccm'];

    /** Emplacements attendus pour un type de pièce : clé de formulaire => [type, face]. */
    public static function emplacements(TypeDocumentKyc $piece): array
    {
        return array_filter([
            'recto' => [$piece, FaceDocument::Recto],
            'verso' => $piece === TypeDocumentKyc::Cnib ? [$piece, FaceDocument::Verso] : null,
            'rccm' => $piece === TypeDocumentKyc::Nif ? [TypeDocumentKyc::Rccm, null] : null,
            'selfie' => [TypeDocumentKyc::Selfie, null],
        ]);
    }

    /**
     * Pièces du dossier en cours : la plus récente par emplacement.
     *
     * @return Collection<string, KycDocument>
     */
    public function dossier(User $user): Collection
    {
        $documents = $user->kycDocuments()->whereIn('type', [...self::PIECES, TypeDocumentKyc::Rccm, TypeDocumentKyc::Selfie])
            ->orderByDesc('id')->get();

        $piece = $this->typePiece($user);
        if (! $piece) {
            return collect();
        }

        return collect(self::emplacements($piece))
            ->map(fn (array $e) => $documents->first(fn (KycDocument $d) => $d->type === $e[0] && $d->face === $e[1]))
            ->filter();
    }

    /** Type de pièce d'identité du dossier en cours (CNIB, passeport ou NIF), ou null si rien n'a été déposé. */
    public function typePiece(User $user): ?TypeDocumentKyc
    {
        return $user->kycDocuments()->whereIn('type', self::PIECES)->latest('id')->first()?->type;
    }

    /**
     * Emplacements à fournir maintenant : tous au premier dépôt, seulement les pièces rejetées ensuite.
     *
     * @return list<string>
     */
    public function aFournir(User $user, ?TypeDocumentKyc $choix = null): array
    {
        return match ($user->statut_kyc) {
            StatutKyc::NonSoumis, null => array_values(array_diff(array_keys(self::emplacements($choix ?? TypeDocumentKyc::Cnib)), self::FACULTATIFS)),
            StatutKyc::Rejete => $this->dossier($user)->filter(fn (KycDocument $d) => $d->statut === StatutDocumentKyc::Rejete)->keys()->values()->all(),
            default => [],
        };
    }

    /**
     * Dépôt (ou renvoi après rejet) des pièces attendues.
     *
     * @param  array<string, UploadedFile>  $fichiers  recto / verso / selfie
     *
     * @throws ValidationException
     */
    public function deposer(User $user, ?TypeDocumentKyc $choix, array $fichiers, ?string $expireLe = null): void
    {
        $premierDepot = in_array($user->statut_kyc, [StatutKyc::NonSoumis, null], true);
        $piece = $premierDepot ? ($choix ?? throw ValidationException::withMessages(['type_piece' => __('public.verification.erreurs.type')])) : $this->typePiece($user);
        abort_unless($premierDepot || $user->statut_kyc === StatutKyc::Rejete, 409);

        $attendus = $this->aFournir($user, $piece);
        $manquants = array_diff($attendus, array_keys(array_filter($fichiers)));
        if ($manquants) {
            throw ValidationException::withMessages(array_fill_keys($manquants, __('public.verification.erreurs.manquant')));
        }

        // Pièce d'identité (CNIB, passeport) déposée : sa date d'expiration est obligatoire (vérifiée ensuite par l'ADMIN).
        $expirable = in_array('recto', $attendus, true) && in_array($piece, [TypeDocumentKyc::Cnib, TypeDocumentKyc::Passeport], true);
        if ($expirable && $expireLe === null) {
            throw ValidationException::withMessages(['piece_expire_le' => __('public.verification.erreurs.expiration')]);
        }

        $emplacements = self::emplacements($piece);
        // Emplacements facultatifs (RCCM) : déposés seulement s'ils sont joints, au premier dépôt.
        foreach (self::FACULTATIFS as $cle) {
            if ($premierDepot && isset($emplacements[$cle], $fichiers[$cle])) {
                $attendus[] = $cle;
            }
        }
        $chemins = [];

        try {
            DB::transaction(function () use ($user, $attendus, $emplacements, $fichiers, &$chemins, $expirable, $expireLe) {
                if ($expirable) {
                    $user->forceFill(['piece_expire_le' => $expireLe, 'piece_avertie_le' => null])->save();
                }
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
                    ]);
                }

                $user->forceFill(['statut_kyc' => StatutKyc::EnAttente])->save();
                $this->journal->ecrire($user, 'kyc.depose', 'User', $user->id, null, ['pieces' => $attendus]);
            });
        } catch (Throwable $e) {
            Storage::disk(self::DISQUE)->delete($chemins);

            throw $e;
        }

        $this->sms($user, 'soumis');
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
                $pieces->isNotEmpty() && $pieces->every(fn (KycDocument $d) => $d->statut === StatutDocumentKyc::Valide) => StatutKyc::Valide,
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
