<?php

namespace App\Http\Controllers;

use App\Models\Carte;
use App\Models\KycDocument;
use App\Services\Kyc\VerificationIdentite;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Photo d'une carte (selfie validé) : donnée personnelle, jamais servie par le seul jeton du QR code. Réservée au
 * titulaire et aux agents autorisés (vérification des cartes, dossiers d'identité).
 */
class CartePhotoController extends Controller
{
    public function __invoke(Request $request, Carte $carte): StreamedResponse
    {
        $user = $request->user();
        abort_unless($user && ($user->id === $carte->user_id || $user->can('cartes.verifier') || $user->can('kyc.voir_pieces')), 403);
        $document = KycDocument::query()->whereKey($carte->contenu['photo'] ?? 0)->where('user_id', $carte->user_id)->firstOrFail();

        return Storage::disk(VerificationIdentite::DISQUE)->response($document->chemin_fichier, null, ['Cache-Control' => 'private, no-store']);
    }
}
