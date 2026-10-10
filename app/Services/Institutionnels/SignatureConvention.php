<?php

namespace App\Services\Institutionnels;

use App\Enums\ModeSignature;
use App\Enums\StatutInstitution;
use App\Models\Convention;
use App\Models\User;
use App\Services\Audit\Journal;
use App\Services\Backoffice\DoubleAuthentification;
use App\Services\Backoffice\RegleGestionException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Signature d'une convention confirmée par le signataire de l'institution :
 * - électronique : code TOTP, horodatage et empreinte (PDF de la convention + version + signataire) ;
 * - ou dépôt de la convention signée en PDF, dont le VALIDATEUR atteste la réception.
 * Impossible tant que le dossier KYB de l'institution n'est pas vérifié.
 */
class SignatureConvention
{
    public const DISQUE = 'local';

    public function __construct(
        private readonly Journal $journal,
        private readonly DoubleAuthentification $totp,
    ) {}

    public function signer(User $signataire, Convention $convention, string $code): void
    {
        $this->verifierSignable($signataire, $convention);

        if (! $signataire->totpActive() || ! $this->totp->verifier($signataire, $code)) {
            throw new RegleGestionException(__('institutionnel.regles.code_signature'));
        }

        DB::transaction(function () use ($signataire, $convention) {
            $horodatage = now();
            $empreinte = hash('sha256', implode('|', [
                $convention->reference, $convention->version, $convention->pdf_empreinte, $signataire->id, $horodatage->toIso8601String(),
            ]));

            $convention->forceFill([
                'signature_mode' => ModeSignature::Electronique,
                'signee_par' => $signataire->id,
                'signee_le' => $horodatage,
                'signature_empreinte' => $empreinte,
            ])->save();

            $this->activerInstitution($convention);
            $this->journal->ecrire($signataire, 'convention.signee', 'Convention', $convention->id, null, [
                'mode' => ModeSignature::Electronique->value, 'version' => $convention->version, 'empreinte' => $empreinte,
            ]);
        });
    }

    public function deposerSignee(User $signataire, Convention $convention, UploadedFile $fichier): void
    {
        $this->verifierSignable($signataire, $convention);

        $chemin = Storage::disk(self::DISQUE)->putFileAs('institutions/'.$convention->institution_id.'/conventions', $fichier, Str::uuid().'.pdf');

        $convention->forceFill([
            'signature_mode' => ModeSignature::Depot,
            'signature_pdf_chemin' => $chemin,
            'signature_pdf_empreinte' => hash_file('sha256', $fichier->getRealPath()),
            'signature_deposee_le' => now(),
            'signee_par' => $signataire->id,
        ])->save();

        $this->journal->ecrire($signataire, 'convention.signee_deposee', 'Convention', $convention->id, null, ['empreinte' => $convention->signature_pdf_empreinte]);
    }

    /**
     * Le VALIDATEUR atteste avoir reçu l'original signé : la convention est alors signée.
     */
    public function attesterReception(User $validateur, Convention $convention): void
    {
        if (! $convention->signatureEnAttente()) {
            throw new RegleGestionException(__('institutionnel.regles.aucun_depot'));
        }

        DB::transaction(function () use ($validateur, $convention) {
            $horodatage = now();
            $convention->forceFill([
                'signee_le' => $horodatage,
                'reception_attestee_par' => $validateur->id,
                'signature_empreinte' => hash('sha256', implode('|', [
                    $convention->reference, $convention->version, $convention->signature_pdf_empreinte, $convention->signee_par, $horodatage->toIso8601String(),
                ])),
            ])->save();

            $this->activerInstitution($convention);
            $this->journal->ecrire($validateur, 'convention.reception_attestee', 'Convention', $convention->id, null, ['empreinte' => $convention->signature_empreinte]);
        });
    }

    private function verifierSignable(User $signataire, Convention $convention): void
    {
        if (! $signataire->estSignataire() || $signataire->institution_id !== $convention->institution_id) {
            abort(403);
        }
        if (! $convention->institution->estVerifiee()) {
            throw new RegleGestionException(__('institutionnel.regles.kyb_non_verifie'));
        }
        if (! $convention->estConfirmee() || $convention->estSignee()) {
            throw new RegleGestionException(__('institutionnel.regles.non_signable'));
        }
    }

    private function activerInstitution(Convention $convention): void
    {
        if ($convention->institution->statut === StatutInstitution::Verifiee) {
            $convention->institution->forceFill(['statut' => StatutInstitution::Active])->save();
        }
    }
}
