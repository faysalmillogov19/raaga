<?php

namespace App\Http\Controllers\Admin;

use App\Enums\Droit;
use App\Http\Controllers\Controller;
use App\Models\Site;
use App\Services\Sites\ComparaisonCapacites;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

/** Capacités prévues par site, comparées aux boutiques importées (par niveau et par type, écarts surlignés). */
class CapacitesController extends Controller
{
    public function index(Request $request, ComparaisonCapacites $comparaison): View
    {
        Gate::authorize(Droit::SitesVoir->value);
        $sites = Site::query()->withCount('capacites')->orderBy('code')->get();
        $site = $sites->firstWhere('id', (int) $request->query('site')) ?? $sites->firstWhere('capacites_count', '>', 0) ?? $sites->first();

        return view('admin.referencement.capacites', [
            'sites' => $sites,
            'site' => $site,
            'capacites' => $site?->capacites()->with('type')->get() ?? collect(),
            'comparaison' => $site ? $comparaison->pour($site) : collect(),
        ]);
    }
}
