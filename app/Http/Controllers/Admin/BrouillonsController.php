<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Validation\SoumissionGroupee;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * « Mes brouillons » (GESTIONNAIRE, RÉGULATEUR) : brouillons et rejetés de toutes les entités qu'il soumet, regroupés
 * par entité, avec le motif de rejet. « Tout soumettre » et « Soumettre » passent par la soumission groupée
 * (action « Soumettre » de chaque fiche, résultat élément par élément).
 */
class BrouillonsController extends Controller
{
    public function __invoke(Request $request): View
    {
        return view('admin.brouillons', ['groupes' => SoumissionGroupee::brouillons($request->user())]);
    }
}
