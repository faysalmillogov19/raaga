<?php

namespace App\Http\Controllers\Publique;

use App\Http\Controllers\Controller;
use App\Services\Sms\LogSmsProvider;
use App\Support\Recette;
use App\Support\VerrouEnvironnement;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * /recette/sms : SMS simulés (aucun envoi réel), pour saisir les codes de confirmation pendant les tests.
 * Accessible sans connexion au compte (on s'en sert justement pour se connecter), jamais en production. En recette
 * (05/11/2026) : protégée par le mot de passe commun des testeurs (RAAGA_SMS_MOT_DE_PASSE_RECETTE, .env seulement),
 * page fermée s'il n'est pas défini ; aucun accès au back-office nécessaire. Poste de développement : ouverte.
 */
class SmsRecetteController extends Controller
{
    /** Session : accès accordé par le mot de passe commun (recette). */
    public const SESSION = 'recette.sms';

    /** Mot de passe commun des testeurs (recette seulement) ; 5 essais par minute. */
    public function acceder(Request $request): RedirectResponse
    {
        abort_unless(VerrouEnvironnement::journalSmsRecette() && Recette::active() && filled(config('raaga.sms.mot_de_passe_recette')), 404);
        $saisi = (string) $request->input('mot_de_passe', '');
        if (! hash_equals((string) config('raaga.sms.mot_de_passe_recette'), $saisi)) {
            return back()->withErrors(['mot_de_passe' => __('public.recette_sms.acces.erreur')]);
        }
        $request->session()->regenerate();
        $request->session()->put(self::SESSION, true);

        return redirect()->route('recette.sms');
    }

    public function __invoke(Request $request): View|JsonResponse
    {
        abort_unless(VerrouEnvironnement::journalSmsRecette(), 404);
        if (Recette::active()) {
            abort_if(blank(config('raaga.sms.mot_de_passe_recette')), 404);
            if (! $request->session()->get(self::SESSION)) {
                return $request->wantsJson() ? response()->json([], 401) : view('public.recette.sms-acces');
            }
        }

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
