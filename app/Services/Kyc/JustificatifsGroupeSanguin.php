<?php

namespace App\Services\Kyc;

use App\Enums\StatutDocumentKyc;
use App\Enums\TypeCarte;
use App\Enums\TypeDocumentKyc;
use App\Models\KycDocument;
use App\Models\User;
use App\Services\Audit\Journal;
use App\Services\Cartes\Cartes;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Justificatif du groupe sanguin (réunion du 05/11/2026) : facultatif, déposé par le titulaire avec son groupe déclaré,
 * hors du dossier d'identité (il n'en conditionne pas la conformité). L'ADMIN le valide ou le refuse ; le groupe n'est
 * imprimé sur la carte de commerçant qu'avec l'accord du titulaire et un justificatif validé. Jamais la valeur du
 * groupe au journal.
 */
class JustificatifsGroupeSanguin
{
    public function __construct(private readonly Journal $journal, private readonly Cartes $cartes) {}

    /** Dernier justificatif déposé, ou null. */
    public static function dernier(User $user): ?KycDocument
    {
        return KycDocument::query()->where('user_id', $user->id)->where('type', TypeDocumentKyc::GroupeSanguin)->latest('id')->first();
    }

    public function deposer(User $user, UploadedFile $fichier): KycDocument
    {
        $chemin = $fichier->storeAs('kyc/'.$user->id, Str::uuid().'.'.$fichier->extension(), VerificationIdentite::DISQUE);
        try {
            $document = KycDocument::create([
                'user_id' => $user->id, 'type' => TypeDocumentKyc::GroupeSanguin, 'face' => null, 'chemin_fichier' => $chemin,
                'nom_original' => Str::limit($fichier->getClientOriginalName(), 200, ''), 'mime' => $fichier->getMimeType(),
                'taille' => $fichier->getSize(), 'empreinte_sha256' => hash_file('sha256', $fichier->getRealPath()),
                'statut' => StatutDocumentKyc::EnAttente, 'televerse_par' => $user->id,
            ]);
        } catch (\Throwable $e) {
            Storage::disk(VerificationIdentite::DISQUE)->delete($chemin);

            throw $e;
        }
        $this->journal->ecrire($user, 'groupe_sanguin.justificatif_depose', 'KycDocument', $document->id);
        $this->synchroniserCarte($user, $user);

        return $document;
    }

    /** Décision de l'ADMIN : validé (groupe imprimé, avec l'accord) ou refusé (motif) ; la carte est recalculée. */
    public function decider(KycDocument $document, User $admin, bool $valide, ?string $motif = null): void
    {
        abort_unless($document->type === TypeDocumentKyc::GroupeSanguin && $document->statut === StatutDocumentKyc::EnAttente, 409);
        $document->forceFill([
            'statut' => $valide ? StatutDocumentKyc::Valide : StatutDocumentKyc::Rejete, 'commentaire' => $valide ? null : $motif,
            'valide_par' => $admin->id, 'valide_le' => now(),
        ])->save();
        $this->journal->ecrire($admin, $valide ? 'groupe_sanguin.justificatif_valide' : 'groupe_sanguin.justificatif_refuse', 'KycDocument', $document->id,
            null, $valide ? null : ['motif' => $motif]);
        $this->synchroniserCarte($document->user, $admin);
    }

    /** Carte de commerçant en cours : le groupe imprimé suit le justificatif (nouvelle version si besoin). */
    private function synchroniserCarte(User $user, User $acteur): void
    {
        if (Cartes::courante($user, TypeCarte::Commercant)) {
            $this->cartes->synchroniser($user, TypeCarte::Commercant, $acteur);
        }
    }
}
