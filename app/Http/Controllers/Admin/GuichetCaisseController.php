<?php

namespace App\Http\Controllers\Admin;

use App\Enums\Droit;
use App\Http\Controllers\Controller;
use App\Services\Guichet\CaisseGuichet;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

/** Caisse de l'agent de guichet : encaissements non clôturés par jour, totaux par moyen, clôture d'un jour. */
class GuichetCaisseController extends Controller
{
    public function __construct(private readonly CaisseGuichet $caisse) {}

    public function index(Request $request): View
    {
        Gate::authorize(Droit::GuichetSouscrire->value);
        $agent = $request->user();

        return view('admin.guichet.caisse', [
            'jours' => $this->caisse->ouverts($agent),
            'clotures' => $this->caisse->clotures($agent, CarbonImmutable::today()),
        ]);
    }

    public function cloturer(Request $request): RedirectResponse
    {
        Gate::authorize(Droit::GuichetSouscrire->value);
        $jour = $request->validate(['jour' => ['required', 'date_format:Y-m-d', 'before_or_equal:today']])['jour'];
        $cloture = $this->caisse->cloturer($request->user(), $jour);

        return redirect()->route('admin.guichet-caisse')->avecSucces('admin.guichet.caisse.cloturee', [
            'nombre' => $cloture->nb_encaissements, 'total' => format_fcfa($cloture->total_montant),
        ]);
    }
}
