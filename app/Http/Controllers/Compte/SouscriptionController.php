<?php

namespace App\Http\Controllers\Compte;

use App\Enums\DocumentAccepte;
use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Services\Souscriptions\PreparationSouscription;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/** /souscrire : choix du nombre d'actions, engagements, réservation (citoyens connectés). */
class SouscriptionController extends Controller
{
    public function __construct(private readonly PreparationSouscription $preparation) {}

    public function create(Request $request): View|RedirectResponse
    {
        $tranche = PreparationSouscription::trancheOuverte();

        // Aucune tranche ouverte : état vide, visible de tous.
        if (! $tranche) {
            return view('public.preparation', [
                'page' => 'souscrire',
                'titre' => __('public.preparation.pages.souscrire.titre'),
            ]);
        }

        $user = $request->user();
        if (! $user) {
            return redirect()->guest(route('connexion'));
        }
        abort_unless($user->hasRole(Role::Citoyen), 403);

        return view('public.souscription.create', [
            'tranche' => $tranche,
            'user' => $user,
            'deja' => $this->preparation->dejaEngage($tranche, $user),
            'justificatifFourni' => $this->preparation->justificatifFourni($user),
            'publicAutorise' => $this->preparation->publicAutorise($tranche, $user),
            'engagements' => PreparationSouscription::ENGAGEMENTS,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $tranche = PreparationSouscription::trancheOuverte();
        abort_unless($tranche, 404);

        $donnees = $request->validate([
            'nb_actions' => ['required', 'integer', 'min:1', 'max:100000000'],
            'engagements' => ['array'],
            'engagements.*' => [Rule::enum(DocumentAccepte::class)],
            'justificatif' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:5120'],
        ], [], __('public.souscription.attributs'));

        $souscription = $this->preparation->reserver(
            $tranche,
            $request->user(),
            (int) $donnees['nb_actions'],
            $donnees['engagements'] ?? [],
            $request->file('justificatif'),
            (string) $request->ip(),
            (string) $request->userAgent(),
        );

        return redirect()->route('paiement.show', $souscription->reference)->with('souscription.reservee', $souscription->reference);
    }

    /** Règlement de la tranche ouverte, tel qu'accepté à la souscription (version affichée). */
    public function reglement(): View
    {
        $tranche = PreparationSouscription::trancheOuverte();
        abort_unless($tranche, 404);

        return view('public.souscription.reglement', ['tranche' => $tranche]);
    }
}
