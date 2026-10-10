<?php

namespace App\Http\Controllers\Publique;

use App\Http\Controllers\Controller;
use App\Services\Publique\CollectePublique;
use Illuminate\View\View;

class AccueilController extends Controller
{
    public function __invoke(CollectePublique $collecte): View
    {

        return view('public.accueil', [
            'etat' => $collecte->etat(),
            'actualites' => $collecte->actualites(),
            // Chiffres de présentation : paramètres de la plateforme (jamais les paramètres de tranche).
            'projet' => plateforme('projet'),
            // Connecté : directement la souscription ; sinon, création de compte.
            'urlSouscrire' => auth()->check() ? route('souscrire') : route('inscription'),
        ]);
    }
}
