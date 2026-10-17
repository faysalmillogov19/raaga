<?php

namespace App\Http\Controllers\Compte;

use App\Enums\TypeDocumentKyc;
use App\Enums\TypeTitulaire;
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
        // RCCM, attestation IFU, justificatif de pouvoir : souvent des PDF.
        $document = ['nullable', 'file', 'mimes:jpg,jpeg,png,pdf', 'mimetypes:image/jpeg,image/png,application/pdf', 'max:5120'];
        $e = __('public.verification.erreurs');

        $donnees = $request->validate([
            // CNIB, CIB-AES ou passeport, et rien d'autre.
            'type_piece' => ['nullable', Rule::in(array_map(fn (TypeDocumentKyc $t) => $t->value, TypeDocumentKyc::piecesIdentite()))],
            'numero_piece' => ['nullable', 'string', 'min:5', 'max:40'],
            // Date d'expiration de la pièce : obligatoire ; la carte Raaga ne la dépasse jamais.
            'piece_expire_le' => ['nullable', 'date_format:Y-m-d', 'after:today'],
            'numero_rccm' => ['nullable', 'string', 'min:4', 'max:60'],
            'numero_ifu' => ['nullable', 'string', 'min:5', 'max:40'],
            'recto' => $fichier, 'verso' => $fichier, 'selfie' => $fichier,
            'rccm' => $document, 'ifu' => $document, 'pouvoir' => $document,
        ], [...$e['fichiers'], 'type_piece.*' => $e['type'], 'piece_expire_le.*' => $e['expiration'], 'numero_piece.*' => $e['numero_piece'],
            'numero_rccm.*' => $e['numero_rccm'], 'numero_ifu.*' => $e['numero_ifu']], __('public.verification.attributs'));

        $this->verification->deposer(
            $request->user(),
            isset($donnees['type_piece']) ? TypeDocumentKyc::from($donnees['type_piece']) : null,
            array_filter($request->only(['recto', 'verso', 'selfie', 'rccm', 'ifu', 'pouvoir'])),
            array_intersect_key($donnees, array_flip(['numero_piece', 'piece_expire_le', 'numero_rccm', 'numero_ifu'])),
        );

        return redirect()->route('espace.verification')->avecSucces('public.verification.envoye');
    }

    /** Type de titulaire (physique ou moral) : modifiable seulement en repassant par la vérification d'identité. */
    public function titulaire(Request $request): RedirectResponse
    {
        $e = __('public.titulaires.erreurs');
        $donnees = $request->validate([
            'type_titulaire' => ['required', Rule::enum(TypeTitulaire::class)],
            'raison_sociale' => ['nullable', 'required_if:type_titulaire,MORALE', 'string', 'min:2', 'max:200'],
            'forme_morale' => ['nullable', 'required_if:type_titulaire,MORALE', Rule::in(TypeTitulaire::FORMES)],
        ], ['type_titulaire.*' => $e['type'], 'raison_sociale.*' => $e['raison_sociale'], 'forme_morale.*' => $e['forme']]);
        $this->verification->changerTitulaire($request->user(), TypeTitulaire::from($donnees['type_titulaire']), $donnees['raison_sociale'] ?? null, $donnees['forme_morale'] ?? null);

        return redirect()->route('espace.verification')->avecSucces('public.titulaires.change');
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
