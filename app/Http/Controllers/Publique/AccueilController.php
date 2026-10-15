<?php

namespace App\Http\Controllers\Publique;

use App\Http\Controllers\Controller;
use App\Models\CampagneBoutiques;
use App\Services\Boutiques\CandidatureBoutiques;
use App\Services\Invest\RaagaInvest;
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
            // Présentation des actions désactivée : annonce « Investir dans Raaga : bientôt » (alerte SMS seulement).
        ]);
    }

    /**
     * Carte « J'investis » : valeur indicative de l'action lue dans les seuls paramètres d'investissement confirmés
     * (sinon aucune valeur, « RAAGA Invest : bientôt ») ; minimum lu dans la tranche ouverte, à défaut la prochaine
     * tranche confirmée ; lien vers la souscription si elle est ouverte, sinon vers l'alerte SMS.
     *
     * @return array{valeur: ?int, minimum: ?int, jauge: ?array, ouverte: bool, lien: string}
     */
    private function investir(): array
    {
        $tranche = Phase::trancheAffichee();
        // Valeur par action et objectif : paramètres d'investissement confirmés (rien n'est écrit en dur).
        $parametres = RaagaInvest::parametres();

        return [
            // Une seule source : la valeur indicative confirmée, jamais le prix de la tranche.
            'valeur' => $parametres['valeur_action'] ?? null,
            'jauge' => RaagaInvest::jauge(),
            'minimum' => $tranche ? max(1, (int) $tranche->min_actions_par_souscription) : null,
            'ouverte' => Phase::souscriptionOuverte(),
            'lien' => Phase::lienSouscription(),
        ];
    }
}
