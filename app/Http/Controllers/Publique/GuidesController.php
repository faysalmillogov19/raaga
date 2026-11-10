<?php

namespace App\Http\Controllers\Publique;

use App\Http\Controllers\Controller;
use App\Support\Guides\Guides;
use Illuminate\View\View;

/**
 * Source des pages statiques de la rubrique « Guides » (10/11/2026) : rendue par raaga:guides sur le poste de
 * développement (ou en recette), enregistrée dans public/guides/. Jamais servie en production : le public lit les
 * pages statiques.
 */
class GuidesController extends Controller
{
    public function __invoke(?string $guide = null): View
    {
        abort_unless(app()->environment(['local', 'recette', 'testing']), 404);
        if ($guide === null) {
            return view('public.guides.index', ['guides' => Guides::tous()]);
        }

        return view('public.guides.guide', ['guide' => Guides::trouver($guide) ?? abort(404)]);
    }
}
