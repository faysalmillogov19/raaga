<?php

namespace App\Http\Controllers\Publique;

use App\Http\Controllers\Controller;
use App\Models\DemandeContact;
use App\Services\Audit\Journal;
use App\Services\Sms\EnvoiSms;
use App\Support\Telephone;
use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Throwable;

/** Page « Nous contacter » : coordonnées (paramètres de la plateforme) et formulaire de demande. */
class ContactController extends Controller
{
    public function show(Request $request): View
    {
        return view('public.contact', ['user' => $request->user()]);
    }

    public function store(Request $request, EnvoiSms $sms, Journal $journal): RedirectResponse
    {
        $donnees = $request->validate([
            'nom' => ['required', 'string', 'max:120'],
            'telephone' => ['required', 'string', function (string $attribut, mixed $valeur, Closure $echec) {
                if (! Telephone::estValide($valeur)) {
                    $echec(__('public.contact.formulaire.telephone_invalide'));
                }
            }],
            'email' => ['nullable', 'email', 'max:255'],
            'objet' => ['required', Rule::in(DemandeContact::OBJETS)],
            'message' => ['required', 'string', 'min:10', 'max:2000'],
        ], [], __('public.contact.formulaire.attributs'));

        $demande = DB::transaction(function () use ($donnees, $request) {
            $demande = DemandeContact::create([
                ...$donnees,
                'telephone' => Telephone::normaliser($donnees['telephone']),
                'user_id' => $request->user()?->id,
            ]);
            $demande->forceFill(['numero' => DemandeContact::numeroPour($demande)])->save();

            return $demande;
        });

        $journal->ecrire($request->user(), 'contact.demande_creee', 'DemandeContact', $demande->id, null, ['numero' => $demande->numero, 'objet' => $demande->objet]);

        // Le message est enregistré même si le SMS échoue : le numéro reste affiché à l'écran.
        try {
            $sms->envoyer($demande->telephone, __('public.contact.formulaire.sms'), ['numero' => $demande->numero]);
        } catch (Throwable $e) {
            Log::warning('SMS de suivi de contact non envoyé', ['demande' => $demande->id, 'erreur' => $e->getMessage()]);
        }

        return redirect()->route('contact')->with('contact.numero', $demande->numero);
    }
}
