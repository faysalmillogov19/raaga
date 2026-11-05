<?php

namespace App\Services\Kyc;

use App\Enums\StatutDocumentKyc;
use App\Enums\StatutKyc;
use App\Enums\TypeDocumentKyc;
use App\Models\KycDocument;
use App\Models\User;
use App\Services\Audit\Journal;
use App\Support\Rccm;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * RCCM du titulaire (réunion du 05/11/2026), modifiable à tout moment depuis « Vérifier mon identité » : numéro, date
 * d'immatriculation, greffe et justificatif. Obligatoire pour une personne morale ; facultatif pour une personne
 * physique (vider les trois champs le retire). Un nouveau justificatif repasse le dossier « en attente » pour l'ADMIN
 * (comme l'attestation IFU) ; un nouveau numéro exige un nouveau justificatif. Jamais imprimé sur la carte.
 */
class RegistreCommerce
{
    public function __construct(private readonly Journal $journal) {}

    /** Dernier justificatif RCCM non refusé, ou null. */
    public static function justificatif(User $user): ?KycDocument
    {
        return KycDocument::query()->where('user_id', $user->id)->where('type', TypeDocumentKyc::Rccm)->latest('id')->first();
    }

    /**
     * @param  array{rccm?: ?string, rccm_immatricule_le?: ?string, rccm_greffe_id?: ?int, rccm_greffe_autre?: ?string}  $donnees  validées par Rccm::regles()
     *
     * @throws ValidationException
     */
    public function enregistrer(User $user, array $donnees, ?UploadedFile $document): void
    {
        $numero = Rccm::normaliser($donnees['rccm'] ?? null);
        $avant = $user->rccm;
        // Personne physique : les trois champs vides retirent le RCCM.
        if ($numero === null) {
            abort_if($user->estPersonneMorale(), 422);
            if ($avant !== null) {
                $user->forceFill(['rccm' => null, 'rccm_immatricule_le' => null, 'rccm_greffe_id' => null, 'rccm_greffe_autre' => null])->save();
                $this->journal->ecrire($user, 'rccm.retire', 'User', $user->id, ['rccm' => $avant]);
            }

            return;
        }
        $existant = self::justificatif($user);
        $sansJustificatif = $existant === null || $existant->statut === StatutDocumentKyc::Rejete || $existant->numero !== $numero;
        if ($document === null && $sansJustificatif) {
            throw ValidationException::withMessages(['document' => __('public.rccm.erreurs.document')]);
        }

        $chemin = null;
        try {
            DB::transaction(function () use ($user, $donnees, $numero, $avant, $document, &$chemin) {
                $user->forceFill(['rccm' => $numero, 'rccm_immatricule_le' => $donnees['rccm_immatricule_le'], 'rccm_greffe_id' => (int) $donnees['rccm_greffe_id'],
                    'rccm_greffe_autre' => Rccm::precisionAutre($donnees)])->save();
                if ($document) {
                    $chemin = $document->storeAs('kyc/'.$user->id, Str::uuid().'.'.$document->extension(), VerificationIdentite::DISQUE);
                    KycDocument::create([
                        'user_id' => $user->id, 'type' => TypeDocumentKyc::Rccm, 'face' => null, 'chemin_fichier' => $chemin,
                        'nom_original' => Str::limit($document->getClientOriginalName(), 200, ''), 'mime' => $document->getMimeType(),
                        'taille' => $document->getSize(), 'empreinte_sha256' => hash_file('sha256', $document->getRealPath()),
                        'statut' => StatutDocumentKyc::EnAttente, 'televerse_par' => $user->id, 'numero' => $numero,
                    ]);
                    // Dossier déjà examiné : l'ADMIN vérifie le nouveau justificatif.
                    if (in_array($user->statut_kyc, [StatutKyc::Valide, StatutKyc::Rejete], true)) {
                        $user->forceFill(['statut_kyc' => StatutKyc::EnAttente])->save();
                    }
                }
                $this->journal->ecrire($user, 'rccm.modifie', 'User', $user->id, ['rccm' => $avant], ['rccm' => $numero, 'justificatif' => $document !== null]);
            });
        } catch (Throwable $e) {
            if ($chemin) {
                Storage::disk(VerificationIdentite::DISQUE)->delete($chemin);
            }

            throw $e;
        }
    }
}
