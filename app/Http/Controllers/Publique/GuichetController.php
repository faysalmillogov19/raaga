<?php

namespace App\Http\Controllers\Publique;

use App\Http\Controllers\Controller;
use App\Models\Guichet;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** « Trouver un guichet » : guichets agréés actifs, filtrables par région puis par commune. */
class GuichetController extends Controller
{
    public function __invoke(Request $request): View
    {
        $regions = Guichet::actifs()->distinct()->orderBy('region')->pluck('region');
        $region = $regions->contains($request->query('region')) ? $request->query('region') : null;

        $communes = $region
            ? Guichet::actifs()->where('region', $region)->distinct()->orderBy('commune')->pluck('commune')
            : collect();
        $commune = $communes->contains($request->query('commune')) ? $request->query('commune') : null;

        $guichets = Guichet::actifs()
            ->when($region, fn ($q) => $q->where('region', $region))
            ->when($commune, fn ($q) => $q->where('commune', $commune))
            ->orderBy('region')->orderBy('commune')->orderBy('nom')
            ->get();

        return view('public.guichets', [
            'regions' => $regions,
            'communes' => $communes,
            'region' => $region,
            'commune' => $commune,
            'guichets' => $guichets,
            'aucunGuichet' => $regions->isEmpty(),
        ]);
    }
}
