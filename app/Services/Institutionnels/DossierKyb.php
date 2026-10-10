<?php

namespace App\Services\Institutionnels;

use App\Enums\StatutDocumentKyc;
use App\Enums\StatutInstitution;
use App\Enums\TypeDocumentKyb;
use App\Models\Institution;
use App\Models\InstitutionDocument;
use App\Models\User;
use App\Services\Audit\Journal;
use App\Services\Backoffice\RegleGestionException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Dossier KYB d'une institution : le signataire dépose les pièces, le VALIDATEUR valide ou rejette
 * chacune avec un motif. Toutes les pièces validées → institution VÉRIFIÉE (condition de signature).
 */
class DossierKyb
{
    public const DISQUE = 'local';

    public function __construct(private readonly Journal $journal) {}

    public function deposer(User $signataire, TypeDocumentKyb $type, UploadedFile $fichier): InstitutionDocument
    {
        $institution = $signataire->institution;
        if ($institution->estSuspendue()) {
            throw new RegleGestionException(__('institutionnel.regles.suspendue'));
        }

        return DB::transaction(function () use ($signataire, $institution, $type, $fichier) {
            $chemin = Storage::disk(self::DISQUE)->putFileAs('institutions/'.$institution->id.'/kyb', $fichier, Str::uuid().'.pdf');

            $document = InstitutionDocument::create([
                'institution_id' => $institution->id,
                'type' => $type,
                'chemin' => $chemin,
                'nom_original' => mb_substr($fichier->getClientOriginalName(), 0, 255),
                'empreinte' => hash_file('sha256', $fichier->getRealPath()),
                'taille' => $fichier->getSize(),
                'depose_par' => $signataire->id,
                'statut' => StatutDocumentKyc::EnAttente,
            ]);

            // Une nouvelle pièce relance la vérification : le dossier n'est plus complet.
            if (in_array($institution->statut, [StatutInstitution::Invitee, StatutInstitution::Verifiee], true)) {
                $institution->forceFill(['statut' => StatutInstitution::DossierEnCours])->save();
            }

            $this->journal->ecrire($signataire, 'kyb.piece_deposee', 'Institution', $institution->id, null, ['type' => $type->value, 'empreinte' => $document->empreinte]);

            return $document;
        });
    }

    public function valider(User $validateur, InstitutionDocument $document): void
    {
        $this->decider($validateur, $document, StatutDocumentKyc::Valide, null);
    }

    public function rejeter(User $validateur, InstitutionDocument $document, string $motif): void
    {
        $this->decider($validateur, $document, StatutDocumentKyc::Rejete, $motif);
    }

    private function decider(User $validateur, InstitutionDocument $document, StatutDocumentKyc $statut, ?string $motif): void
    {
        $institution = $document->institution;
        $enVigueur = $institution->piecesEnVigueur()->get($document->type->value);

        if (! $enVigueur?->is($document) || $document->statut !== StatutDocumentKyc::EnAttente) {
            throw new RegleGestionException(__('institutionnel.regles.piece_traitee'));
        }

        DB::transaction(function () use ($validateur, $document, $statut, $motif, $institution) {
            $document->forceFill(['statut' => $statut, 'motif_rejet' => $motif, 'verifie_par' => $validateur->id, 'verifie_le' => now()])->save();
            $this->journal->ecrire($validateur, $statut === StatutDocumentKyc::Valide ? 'kyb.piece_validee' : 'kyb.piece_rejetee',
                'Institution', $institution->id, null, ['type' => $document->type->value, 'motif' => $motif]);

            if ($institution->statut === StatutInstitution::DossierEnCours && $institution->dossierComplet()) {
                $institution->forceFill(['statut' => StatutInstitution::Verifiee, 'verifiee_le' => now()])->save();
                $this->journal->ecrire($validateur, 'kyb.dossier_verifie', 'Institution', $institution->id);
            }
        });
    }
}
