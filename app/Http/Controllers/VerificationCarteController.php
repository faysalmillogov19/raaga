<?php

namespace App\Http\Controllers;

use App\Models\Carte;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
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

    /**
     * Portiques : { "valide": true|false } seulement ; clé obligatoire (en-tête X-Cle-Portique). Une requête avec la
     * bonne clé n'est jamais limitée (un portique scanne en continu) ; les essais sans clé ou avec une mauvaise clé sont
     * limités par adresse IP (au-delà : 429 avec le délai d'attente ; la bonne clé, elle, passe toujours).
     */
    public function portique(Request $request, string $jeton): JsonResponse
    {
        $cle = (string) config('raaga.cartes.cle_portiques');
        if ($cle === '' || ! hash_equals($cle, (string) $request->header('X-Cle-Portique'))) {
            $compteur = 'portiques-cle:'.$request->ip();
            if (RateLimiter::tooManyAttempts($compteur, (int) config('raaga.cartes.tentatives_par_minute'))) {
                $secondes = RateLimiter::availableIn($compteur);

                return response()->json(['erreur' => 'trop_de_tentatives',
                    'message' => __('public.cartes.verification.trop_de_tentatives', ['secondes' => $secondes])], 429, ['Retry-After' => $secondes]);
            }
            RateLimiter::hit($compteur, 60);

            return response()->json(['erreur' => 'cle_invalide'], 401);
        }
        $carte = Carte::query()->where('jeton', $jeton)->first();

        return response()->json(['valide' => (bool) $carte?->estValide()])->header('Cache-Control', 'no-store');
    }
}
