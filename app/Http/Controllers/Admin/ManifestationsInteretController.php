<?php

namespace App\Http\Controllers\Admin;

use App\Enums\Droit;
use App\Enums\ProfilInvestisseur;
use App\Http\Controllers\Controller;
use App\Models\ManifestationInteret;
use App\Services\Invest\RaagaInvest;
use App\Support\Tableau\Colonne;
use App\Support\Tableau\Filtre;
use App\Support\Tableau\ResultatTableau;
use App\Support\Tableau\Tableau;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Manifestations d'intérêt RAAGA Invest (simulations non engageantes des citoyens) : liste avec export pour le
 * RÉGULATEUR et l'ADMIN ; le GESTIONNAIRE n'y a pas accès. Aucun paiement n'y est associé.
 */
class ManifestationsInteretController extends Controller
{
    public function index(Request $request): Response|StreamedResponse|View
    {
        Gate::authorize(Droit::InteretsVoir->value);
        $c = __('admin.interets.colonnes');

        return Tableau::pour(ManifestationInteret::query()->with('user'), 'interets')
            ->colonnes([
                Colonne::make('reference', $c['reference'])->triable()->recherchable()->classe('font-mono font-semibold'),
                Colonne::make('date', $c['date'])->triable('created_at')->classe('whitespace-nowrap')->valeur(fn (ManifestationInteret $m) => $m->created_at->format('d/m/Y H:i')),
                Colonne::make('citoyen', $c['citoyen'])->recherchable(fn ($q, $terme) => $q->whereHas('user', fn ($u) => $u->whereContient('name', $terme)))
                    ->valeur(fn (ManifestationInteret $m) => $m->user?->name),
                Colonne::make('profil', $c['profil'])->triable()->valeur(fn (ManifestationInteret $m) => $m->profil->label()),
                Colonne::make('pays_residence', $c['pays'])->triable()->recherchable(),
                Colonne::make('nombre_actions', $c['nombre'])->numerique()->triable()->valeur(fn (ManifestationInteret $m) => format_nombre($m->nombre_actions))
                    ->texte(fn (ManifestationInteret $m) => $m->nombre_actions),
                Colonne::make('montant', $c['montant'])->numerique()->triable()->valeur(fn (ManifestationInteret $m) => format_fcfa($m->montant))
                    ->texte(fn (ManifestationInteret $m) => $m->montant),
            ])
            ->filtres([Filtre::make('profil', $c['profil'], collect(ProfilInvestisseur::cases())->mapWithKeys(fn ($p) => [$p->value => $p->label()])->all(), 'profil')])
            ->triParDefaut('date', 'desc')
            ->unite(__('admin.interets.unite'))
            ->export('Raaga_manifestations-interet')
            ->repondre($request, fn (ResultatTableau $t) => view('admin.interets.index', ['tableau' => $t, 'indicateurs' => RaagaInvest::indicateurs()]));
    }
}
