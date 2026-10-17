<?php

namespace App\Http\Controllers;

use App\Models\Carte;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Vérification d'une carte Raaga par son QR code (URL avec un jeton aléatoire, sans donnée personnelle).
 * Agent connecté autorisé (cartes.verifier) : page détaillée du back-office (photo, nom, type, emplacements, statut),
 * scan tracé. Sans connexion : seulement « Carte valide » ou « Carte non valide ». Portiques : réponse valide ou non
 * valide, protégée par une clé (RAAGA_CLE_PORTIQUES).
 */
class VerificationCarteController extends Controller
{
    public function show(Request $request, string $jeton): View|RedirectResponse
    {
        if ($request->user()?->can('cartes.verifier')) {
            return redirect()->route('admin.guichet.carte', $jeton);
        }
        $carte = Carte::query()->where('jeton', $jeton)->first();

        return view('public.cartes.verification', ['valide' => (bool) $carte?->estValide()]);
    }

    /** Portiques : { "valide": true|false } seulement ; clé obligatoire (en-tête X-Cle-Portique). */
    public function portique(Request $request, string $jeton): JsonResponse
    {
        $cle = (string) config('raaga.cartes.cle_portiques');
        if ($cle === '' || ! hash_equals($cle, (string) $request->header('X-Cle-Portique'))) {
            return response()->json(['erreur' => 'cle_invalide'], 401);
        }
        $carte = Carte::query()->where('jeton', $jeton)->first();

        return response()->json(['valide' => (bool) $carte?->estValide()])->header('Cache-Control', 'no-store');
    }
}
