<?php

namespace App\Http\Controllers\Publique;

use App\Http\Controllers\Controller;
use App\Models\AlerteSouscription;
use App\Support\Phase;
use App\Support\Telephone;
use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * « Souscription bientôt ouverte » : inscription à l'alerte SMS (numéro burkinabè et consentement explicite).
 * Une même personne peut s'inscrire plusieurs fois sans doublon ; la réponse est la même (aucune énumération).
 */
class AlerteSouscriptionController extends Controller
{
    public function show(): View|RedirectResponse
    {
        // Souscription ouverte : plus d'alerte, directement la souscription.
        if (Phase::souscriptionOuverte()) {
            return redirect(Phase::lienSouscription());
        }

        return view('public.souscription.bientot');
    }

    public function store(Request $request): RedirectResponse
    {
        $request->validate([
            'telephone' => ['required', 'string', 'max:20', fn (string $a, mixed $v, Closure $echec) => Telephone::estValide((string) $v) ?: $echec(__('public.demarches.alerte.erreurs.telephone'))],
            'consentement' => ['accepted'],
        ], ['consentement.accepted' => __('public.demarches.alerte.erreurs.consentement'), 'telephone.required' => __('public.demarches.alerte.erreurs.telephone')]);

        AlerteSouscription::query()->updateOrCreate(['telephone' => Telephone::normaliser($request->input('telephone'))], ['consentement_le' => now()]);

        // Retour à la page d'où vient l'inscription : la page « Souscription bientôt ouverte » ou l'annonce de l'accueil.
        return back()->with('alerte_enregistree', true);
    }
}
