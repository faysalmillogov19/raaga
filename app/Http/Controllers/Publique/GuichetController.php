<?php

namespace App\Http\Controllers\Publique;

use App\Http\Controllers\Controller;
use App\Models\Guichet;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;

/** « Trouver un guichet » : guichets agréés actifs, filtrables par région puis par commune (référentiel des communes). */
class GuichetController extends Controller
{
    public function __invoke(Request $request): View
    {
        $tous = Guichet::actifs()->with('commune.province.region')->get()
            ->sortBy(fn (Guichet $g) => Str::ascii(($g->commune?->province->region->nom ?? '').' '.($g->commune?->nom ?? '').' '.$g->nom))->values();
        $regions = $tous->map(fn (Guichet $g) => $g->commune?->province->region->nom)->filter()->unique()->values();
        $region = $regions->contains($request->query('region')) ? $request->query('region') : null;

        $dansRegion = $tous->filter(fn (Guichet $g) => $region === null || $g->commune?->province->region->nom === $region);
        $communes = $region ? $dansRegion->map(fn (Guichet $g) => $g->commune?->nom)->filter()->unique()->values() : collect();
        $commune = $communes->contains($request->query('commune')) ? $request->query('commune') : null;

        return view('public.guichets', [
            'regions' => $regions,
            'communes' => $communes,
            'region' => $region,
            'commune' => $commune,
            'guichets' => $dansRegion->filter(fn (Guichet $g) => $commune === null || $g->commune?->nom === $commune)->values(),
            'aucunGuichet' => $regions->isEmpty(),
        ]);
    }
}
