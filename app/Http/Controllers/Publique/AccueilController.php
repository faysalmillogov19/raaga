<?php

namespace App\Http\Controllers\Publique;

use App\Http\Controllers\Controller;
use App\Models\CampagneBoutiques;
use App\Services\Boutiques\CandidatureBoutiques;
use App\Services\Publique\AccueilBoutiques;
use App\Support\Diapositives;
use App\Support\Phase;
use Illuminate\View\View;

/**
 * Accueil public : carrousel, puis les deux démarches côte à côte (investir, obtenir une boutique), chiffres, étapes,
 * versements, sites, questions, aide. La carte « J'investis » n'apparaît que si la présentation des actions est visible.
 */
class AccueilController extends Controller
{
    /** Questions de la FAQ « Boutiques et cautions » mises en avant sur l'accueil (les plus utiles). */
    private const QUESTIONS_ACCUEIL = [0, 1, 2, 4, 5];

    public function __invoke(AccueilBoutiques $accueil): View
    {
        $campagne = CampagneBoutiques::ouverte();
        $ouverte = $campagne && CandidatureBoutiques::accepteCandidatures($campagne);
        $questions = __('public.faq.groupes.boutiques.questions');

        return view('public.accueil-boutiques', [
            'campagne' => $campagne,
            'ouverte' => $ouverte,
            'chiffres' => $accueil->chiffres(),
            'durees' => $accueil->durees(),
            'sites' => $accueil->sites(),
            'diapositives' => Diapositives::affichees(),
            'questions' => array_values(array_filter(array_map(fn (int $i) => $questions[$i] ?? null, self::QUESTIONS_ACCUEIL))),
            // Campagne ouverte : candidature (après connexion) ; sinon, la page des boutiques ou la création de compte.
            'urlCandidater' => $ouverte
                ? (auth()->check() ? route('boutiques.candidater') : route('inscription'))
                : (auth()->check() ? route('boutiques') : route('inscription')),
            'investir' => Phase::presentationActions() ? $this->investir() : null,
            // Présentation des actions désactivée : annonce « Investir dans Raaga — bientôt » (alerte SMS seulement).
        ]);
    }

    /**
     * Carte « J'investis » : prix de l'action et minimum lus dans la tranche ouverte, à défaut la prochaine tranche
     * confirmée ; lien vers la souscription si elle est ouverte, sinon vers l'alerte SMS.
     *
     * @return array{prix: ?int, minimum: ?int, ouverte: bool, lien: string}
     */
    private function investir(): array
    {
        $tranche = Phase::trancheAffichee();

        return [
            'prix' => $tranche?->valeur_unitaire,
            'minimum' => $tranche ? max(1, (int) $tranche->min_actions_par_souscription) : null,
            'ouverte' => Phase::souscriptionOuverte(),
            'lien' => Phase::lienSouscription(),
        ];
    }
}
