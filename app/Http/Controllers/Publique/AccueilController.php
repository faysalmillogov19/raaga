<?php

namespace App\Http\Controllers\Publique;

use App\Enums\StatutCampagneBoutiques;
use App\Http\Controllers\Controller;
use App\Models\CampagneBoutiques;
use App\Services\Boutiques\CandidatureBoutiques;
use App\Services\Publique\CollectePublique;
use App\Support\Phase;
use Illuminate\View\View;

class AccueilController extends Controller
{
    public function __invoke(CollectePublique $collecte): View
    {
        // Phase 1 : accueil centré sur la campagne d'attribution des boutiques.
        if (! Phase::actionsPubliques()) {
            $campagne = CampagneBoutiques::ouverte()
                ?? CampagneBoutiques::query()->whereIn('statut', [StatutCampagneBoutiques::Cloturee, StatutCampagneBoutiques::Attribuee])->latest('cloturee_le')->first();

            return view('public.accueil-boutiques', [
                'campagne' => $campagne?->load('categories'),
                'ouverte' => $campagne && CandidatureBoutiques::accepteCandidatures($campagne),
                'questions' => array_slice(__('public.faq.groupes.boutiques.questions'), 0, 4),
            ]);
        }

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
