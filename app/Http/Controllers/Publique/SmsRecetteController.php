<?php

namespace App\Http\Controllers\Publique;

use App\Http\Controllers\Controller;
use App\Services\Sms\LogSmsProvider;
use App\Support\VerrouEnvironnement;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * /recette/sms : SMS simulés (aucun envoi réel), pour saisir les codes de confirmation pendant les tests.
 * Accessible sans connexion (on s'en sert justement pour se connecter), jamais en production.
 */
class SmsRecetteController extends Controller
{
    public function __invoke(Request $request): View|JsonResponse
    {
        abort_unless(VerrouEnvironnement::journalSmsRecette(), 404);

        $messages = array_map(function (array $m) {
            preg_match('/\b(\d{6})\b/', $m['message'], $code);

            return [...$m, 'code' => $code[1] ?? null];
        }, LogSmsProvider::recents());

        if ($request->wantsJson()) {
            return response()->json($messages)->header('Cache-Control', 'no-store');
        }

        return view('public.recette.sms', ['messages' => $messages]);
    }
}
