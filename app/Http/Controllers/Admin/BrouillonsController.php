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
        // Filtre du menu « Mes soumissions » (RÉGULATEUR) : brouillons, rejetés ou en attente de validation.
        $vue = in_array($request->query('vue'), SoumissionGroupee::VUES, true) ? $request->query('vue') : null;

        return view('admin.brouillons', ['groupes' => SoumissionGroupee::brouillons($request->user(), $vue), 'vue' => $vue]);
    }
}
