<?php

namespace Tests\Support;

use App\Enums\FaceDocument;
use App\Enums\StatutDocumentKyc;
use App\Enums\TypeDocumentKyc;
use App\Models\KycDocument;
use App\Models\User;
use App\Services\Kyc\VerificationIdentite;

/**
 * Dossier d'identité complet déposé (pièces en attente) : CNIB recto et verso avec numéro et date d'expiration, selfie ;
 * pour une personne morale, aussi l'attestation IFU (avec son numéro), le justificatif de pouvoir et le justificatif
 * RCCM (05/11/2026 ; personne physique qui a déclaré un RCCM aussi).
 */
final class DossierKyc
{
    public static function complet(User $user, string $numeroPiece = 'B12345678', ?string $expireLe = null, TypeDocumentKyc $type = TypeDocumentKyc::Cnib): void
    {
        $expireLe ??= now()->addYears(5)->toDateString();
        // Passeport : recto seul (10/11/2026).
        $pieces = array_values(array_filter([
            [$type, FaceDocument::Recto, $numeroPiece, $expireLe],
            $type === TypeDocumentKyc::Passeport ? null : [$type, FaceDocument::Verso, null, null],
            [TypeDocumentKyc::Selfie, null, null, null],
        ]));
        // Attestation IFU : personne morale, ou personne physique qui a donné son IFU.
        if (VerificationIdentite::avecIfu($user) && ! $user->kycDocuments()->where('type', TypeDocumentKyc::Ifu)->exists()) {
            $pieces[] = [TypeDocumentKyc::Ifu, null, $user->ifu, null];
        }
        if ($user->estPersonneMorale()) {
            $pieces[] = [TypeDocumentKyc::JustifPouvoir, null, null, null];
        }
        if (VerificationIdentite::avecRccm($user)) {
            $pieces[] = [TypeDocumentKyc::Rccm, null, $user->rccm, null];
        }
        foreach ($pieces as [$type, $face, $numero, $expire]) {
            KycDocument::create(['user_id' => $user->id, 'type' => $type, 'face' => $face, 'chemin_fichier' => 'kyc/test/'.$type->value.($face?->value ?? ''),
                'mime' => 'image/jpeg', 'taille' => 1000, 'empreinte_sha256' => str_repeat('a', 64), 'statut' => StatutDocumentKyc::EnAttente,
                'televerse_par' => $user->id, 'numero' => $numero, 'expire_le' => $expire]);
        }
        $user->forceFill(['numero_piece' => $numeroPiece, 'piece_expire_le' => $expireLe])->saveQuietly();
    }
}
