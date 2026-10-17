<?php

namespace App\Services\Cartes;

use App\Enums\Role;
use App\Enums\StatutDocumentKyc;
use App\Enums\TypeCarte;
use App\Enums\TypeDocumentKyc;
use App\Models\KycDocument;
use App\Models\User;
use App\Services\Audit\Journal;
use App\Services\Kyc\VerificationIdentite;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Photo de la carte du PERSONNEL : déposée par le membre du back-office, validée (ou refusée) par l'ADMIN. Stockée sur le
 * disque privé comme un selfie ; seule la dernière photo validée figure sur la carte (le titulaire ne la modifie jamais
 * directement).
 */
class PhotosPersonnel
{
    public function __construct(private readonly Journal $journal, private readonly Cartes $cartes) {}

    /** @return Builder<KycDocument> photos du personnel en attente de validation */
    public static function enAttente(): Builder
    {
        return KycDocument::query()->where('type', TypeDocumentKyc::Selfie)->where('statut', StatutDocumentKyc::EnAttente)
            ->whereHas('user.role', fn (Builder $q) => $q->whereIn('code', array_map(fn (Role $r) => $r->value, Role::backoffice())));
    }

    public static function derniere(User $user): ?KycDocument
    {
        return KycDocument::query()->where('user_id', $user->id)->where('type', TypeDocumentKyc::Selfie)->latest('id')->first();
    }

    public function deposer(User $user, UploadedFile $fichier): KycDocument
    {
        abort_unless($user->estBackoffice(), 403);
        $chemin = $fichier->storeAs('kyc/'.$user->id, Str::uuid().'.'.$fichier->extension(), VerificationIdentite::DISQUE);

        try {
            return DB::transaction(function () use ($user, $fichier, $chemin) {
                // Une seule photo en attente : la précédente est remplacée.
                KycDocument::query()->where('user_id', $user->id)->where('type', TypeDocumentKyc::Selfie)->where('statut', StatutDocumentKyc::EnAttente)
                    ->update(['statut' => StatutDocumentKyc::Rejete, 'commentaire' => __('admin.cartes.photos.remplacee')]);
                $document = KycDocument::create([
                    'user_id' => $user->id, 'type' => TypeDocumentKyc::Selfie, 'chemin_fichier' => $chemin,
                    'nom_original' => Str::limit($fichier->getClientOriginalName(), 200, ''), 'mime' => $fichier->getMimeType(),
                    'taille' => $fichier->getSize(), 'empreinte_sha256' => hash_file('sha256', $fichier->getRealPath()),
                    'statut' => StatutDocumentKyc::EnAttente, 'televerse_par' => $user->id,
                ]);
                $this->journal->ecrire($user, 'carte.photo_deposee', 'KycDocument', $document->id);

                return $document;
            });
        } catch (Throwable $e) {
            Storage::disk(VerificationIdentite::DISQUE)->delete($chemin);

            throw $e;
        }
    }

    /** Validée par l'ADMIN : la carte PERSONNEL (si elle existe) est régénérée avec la nouvelle photo. */
    public function valider(KycDocument $document, User $admin): void
    {
        abort_unless($document->statut === StatutDocumentKyc::EnAttente && $document->type === TypeDocumentKyc::Selfie, 409);
        $document->forceFill(['statut' => StatutDocumentKyc::Valide, 'valide_par' => $admin->id, 'valide_le' => now()])->save();
        $this->journal->ecrire($admin, 'carte.photo_validee', 'KycDocument', $document->id, null, ['titulaire' => $document->user_id]);
        if (Cartes::courante($document->user, TypeCarte::Personnel)) {
            $this->cartes->synchroniser($document->user, TypeCarte::Personnel, $admin);
        }
    }

    public function refuser(KycDocument $document, User $admin, string $motif): void
    {
        abort_unless($document->statut === StatutDocumentKyc::EnAttente && $document->type === TypeDocumentKyc::Selfie, 409);
        $document->forceFill(['statut' => StatutDocumentKyc::Rejete, 'commentaire' => $motif, 'valide_par' => $admin->id, 'valide_le' => now()])->save();
        $this->journal->ecrire($admin, 'carte.photo_refusee', 'KycDocument', $document->id, null, ['titulaire' => $document->user_id, 'motif' => $motif]);
    }
}
