<?php

namespace App\Http\Controllers\Publique;

use App\Enums\ProfilInvestisseur;
use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Models\ManifestationInteret;
use App\Services\Audit\Journal;
use App\Services\Invest\RaagaInvest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * RAAGA Invest : simulateur (nombre d'actions × valeur indicative = montant, part de l'objectif de collecte,
 * indicative) et manifestation d'intérêt d'un citoyen connecté (simulation non engageante, référence attribuée,
 * aucun paiement). Valeurs tirées des paramètres d'investissement confirmés : sans eux, ni simulation ni manifestation.
 */
class InvestController extends Controller
{
    public function __construct(private readonly Journal $journal) {}

    public function simulateur(Request $request): View
    {
        $nombre = max(1, min(10_000_000, (int) $request->query('actions', $this->minimum())));

        return view('public.invest.simulateur', [
            'parametres' => RaagaInvest::parametres(),
            'simulation' => RaagaInvest::simuler($nombre),
            'nombre' => $nombre,
            'citoyen' => $request->user()?->hasRole(Role::Citoyen) ?? false,
            'profils' => ProfilInvestisseur::cases(),
        ]);
    }

    public function interet(Request $request): RedirectResponse
    {
        $donnees = $request->validate([
            'profil' => ['required', Rule::enum(ProfilInvestisseur::class)],
            'pays_residence' => ['required', 'string', 'max:80'],
            'nombre_actions' => ['required', 'integer', 'min:1', 'max:10000000'],
            'simulation_comprise' => ['accepted'],
        ], ['simulation_comprise.accepted' => __('public.invest.erreurs.simulation_comprise')], __('public.invest.attributs'));

        $simulation = RaagaInvest::simuler((int) $donnees['nombre_actions'])
            ?? throw ValidationException::withMessages(['nombre_actions' => __('public.invest.erreurs.indisponible')]);

        $manifestation = ManifestationInteret::create([
            'user_id' => $request->user()->id, 'profil' => $donnees['profil'], 'pays_residence' => trim($donnees['pays_residence']),
            'nombre_actions' => $simulation['nombre'], 'valeur_action' => $simulation['valeur_action'], 'montant' => $simulation['montant'],
            'simulation_comprise_le' => now(),
        ]);
        $this->journal->ecrire($request->user(), 'invest.interet', 'ManifestationInteret', $manifestation->id, null,
            ['reference' => $manifestation->reference, 'nombre_actions' => $manifestation->nombre_actions, 'montant' => $manifestation->montant]);

        return redirect()->route('espace', ['onglet' => 'interets'])
            ->with('succes', __('public.invest.messages.enregistree', ['reference' => $manifestation->reference]));
    }

    /** Nombre minimum d'actions (conditions publiques, 1 sinon) : valeur de départ du simulateur. */
    private function minimum(): int
    {
        return RaagaInvest::conditions()['minimum'] ?? 1;
    }
}
