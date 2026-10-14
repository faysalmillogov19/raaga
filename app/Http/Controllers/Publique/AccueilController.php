<?php

namespace App\Http\Controllers\Publique;

use App\Http\Controllers\Controller;
use App\Models\CampagneBoutiques;
use App\Services\Boutiques\CandidatureBoutiques;
use App\Services\Publique\AccueilBoutiques;
use App\Services\Publique\CollectePublique;
use App\Support\Diapositives;
use App\Support\Phase;
use Illuminate\View\View;

class AccueilController extends Controller
{
    /** Questions de la FAQ « Boutiques et cautions » mises en avant sur l'accueil (les plus utiles). */
    private const QUESTIONS_ACCUEIL = [0, 1, 2, 4, 5];

    public function __invoke(CollectePublique $collecte, AccueilBoutiques $accueil): View
    {
        // Phase 1 : accueil centré sur les boutiques (chiffres, étapes, versements, sites, questions, aide).
        if (! Phase::actionsPubliques()) {
            $campagne = CampagneBoutiques::ouverte();
            $questions = __('public.faq.groupes.boutiques.questions');

            return view('public.accueil-boutiques', [
                'campagne' => $campagne,
                'ouverte' => $campagne && CandidatureBoutiques::accepteCandidatures($campagne),
                'chiffres' => $accueil->chiffres(),
                'durees' => $accueil->durees(),
                'sites' => $accueil->sites(),
                'diapositives' => Diapositives::affichees(),
                'questions' => array_values(array_filter(array_map(fn (int $i) => $questions[$i] ?? null, self::QUESTIONS_ACCUEIL))),
                // Campagne ouverte : candidature (après connexion) ; sinon, la page des boutiques ou la création de compte.
                'urlCandidater' => $campagne && CandidatureBoutiques::accepteCandidatures($campagne)
                    ? (auth()->check() ? route('boutiques.candidater') : route('inscription'))
                    : (auth()->check() ? route('boutiques') : route('inscription')),
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
