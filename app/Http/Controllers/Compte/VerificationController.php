<?php

namespace App\Http\Controllers\Compte;

use App\Enums\TypeDocumentKyc;
use App\Http\Controllers\Controller;
use App\Models\KycDocument;
use App\Services\Kyc\VerificationIdentite;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** /espace/verification : dépôt des pièces d'identité du citoyen connecté et suivi du statut. */
class VerificationController extends Controller
{
    public function __construct(private readonly VerificationIdentite $verification) {}

    public function show(Request $request): View
    {
        $user = $request->user();

        return view('public.espace.verification', [
            'user' => $user,
            'dossier' => $this->verification->dossier($user),
            'typePiece' => $this->verification->typePiece($user),
            'aFournir' => $this->verification->aFournir($user),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $fichier = ['nullable', 'file', 'mimes:jpg,jpeg,png', 'mimetypes:image/jpeg,image/png', 'max:5120'];
        // Attestation NIF et RCCM : souvent des PDF.
        $document = ['nullable', 'file', 'mimes:jpg,jpeg,png,pdf', 'mimetypes:image/jpeg,image/png,application/pdf', 'max:5120'];

        $donnees = $request->validate([
            'type_piece' => ['nullable', Rule::in(array_map(fn (TypeDocumentKyc $t) => $t->value, VerificationIdentite::PIECES))],
            'recto' => $request->input('type_piece') === TypeDocumentKyc::Nif->value ? $document : $fichier,
            'verso' => $fichier,
            'rccm' => $document,
            'selfie' => $fichier,
            // Date d'expiration de la pièce (CNIB, passeport) : la carte Raaga ne la dépasse jamais.
            'piece_expire_le' => ['nullable', 'date_format:Y-m-d', 'after:today'],
        ], [...__('public.verification.erreurs.fichiers'), 'piece_expire_le.*' => __('public.verification.erreurs.expiration')], __('public.verification.attributs'));

        $this->verification->deposer(
            $request->user(),
            isset($donnees['type_piece']) ? TypeDocumentKyc::from($donnees['type_piece']) : null,
            array_filter($request->only(['recto', 'verso', 'rccm', 'selfie'])),
            $donnees['piece_expire_le'] ?? null,
        );

        return redirect()->route('espace.verification')->avecSucces('public.verification.envoye');
    }

    /** Fichier d'une pièce : uniquement pour son titulaire (le VALIDATEUR passe par le back-office). */
    public function fichier(Request $request, KycDocument $document): StreamedResponse
    {
        abort_unless($document->user_id === $request->user()->id, 403);

        return self::servir($document);
    }

    /** Réponse commune aux routes contrôlées : jamais mise en cache, jamais interprétée par le navigateur. */
    public static function servir(KycDocument $document): StreamedResponse
    {
        abort_unless(Storage::disk(VerificationIdentite::DISQUE)->exists($document->chemin_fichier), 404);

        return Storage::disk(VerificationIdentite::DISQUE)->response($document->chemin_fichier, null, [
            'Content-Type' => $document->mime,
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; img-src 'self'; sandbox",
        ]);
    }
}
