<?php

namespace App\Http\Controllers\Admin;

use App\Enums\Droit;
use App\Enums\StatutReservationBoutique;
use App\Http\Controllers\Controller;
use App\Models\Carte;
use App\Models\ReservationBoutique;
use App\Models\User;
use App\Services\Audit\Journal;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

/**
 * Guichet (Agent de guichet, ADMIN) : vérification d'une carte scannée (photo, nom, type, emplacements, statut en grand,
 * chaque scan tracé au journal d'audit), recherche par numéro de carte, dossier du titulaire (sans aucun montant).
 */
class GuichetController extends Controller
{
    public function __construct(private readonly Journal $journal) {}

    public function index(): View
    {
        Gate::authorize(Droit::CartesVerifier->value);

        return view('admin.guichet.index');
    }

    /** Numéro de carte (RG-C-000123) ou adresse scannée : ouvre la vérification de la carte. */
    public function rechercher(Request $request): RedirectResponse
    {
        Gate::authorize(Droit::CartesVerifier->value);
        $saisie = trim((string) $request->validate(['carte' => ['required', 'string', 'max:300']])['carte']);
        $jeton = preg_match('#/c/([A-Za-z0-9]+)#', $saisie, $m) ? $m[1] : null;
        $carte = Carte::query()->when($jeton, fn ($q) => $q->where('jeton', $jeton), fn ($q) => $q->where('numero', mb_strtoupper($saisie)))->first();
        if (! $carte) {
            return back()->withErrors(['carte' => __('admin.guichet.erreurs.introuvable')])->withInput();
        }

        return redirect()->route('admin.guichet.carte', $carte->jeton);
    }

    public function carte(Request $request, string $jeton): View
    {
        Gate::authorize(Droit::CartesVerifier->value);
        $carte = Carte::query()->where('jeton', $jeton)->with('user')->first();
        // Chaque scan d'un agent connecté est tracé (carte inconnue comprise).
        $this->journal->ecrire($request->user(), 'carte.scannee', 'Carte', $carte?->id, null,
            ['numero' => $carte?->numero, 'resultat' => $carte?->estValide() ? 'valide' : 'non_valide']);

        return view('admin.guichet.carte', ['carte' => $carte]);
    }

    public function titulaire(User $titulaire): View
    {
        Gate::authorize(Droit::CartesVerifier->value);
        abort_unless($titulaire->cartes()->exists(), 404);

        return view('admin.guichet.titulaire', [
            'titulaire' => $titulaire,
            'cartes' => $titulaire->cartes()->latest('id')->get(),
            'emplacements' => ReservationBoutique::query()->where('user_id', $titulaire->id)->where('statut', StatutReservationBoutique::Attribuee)
                ->with('boutique.site')->get()->sortBy(fn (ReservationBoutique $r) => $r->boutique?->code)->values(),
        ]);
    }
}
