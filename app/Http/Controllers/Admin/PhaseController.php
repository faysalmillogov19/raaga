<?php

namespace App\Http\Controllers\Admin;

use App\Enums\Droit;
use App\Http\Controllers\Controller;
use App\Models\DecisionPhase;
use App\Services\Audit\Journal;
use App\Services\Validation\NotificationDecision;
use App\Support\Phase;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Démarches du site public : « présentation des actions visible » et « souscription d'actions ouverte ».
 * Le RÉGULATEUR propose (et peut retirer sa proposition), le VALIDATEUR confirme ou rejette (quatre yeux) ; chaque décision reste dans l'historique
 * et au journal d'audit. Le site public applique la dernière décision confirmée.
 */
class PhaseController extends Controller
{
    public function __construct(private readonly Journal $journal, private readonly NotificationDecision $notifications) {}

    public function index(): View
    {
        $decisions = DecisionPhase::query()->with(['auteur', 'decideur'])->orderByDesc('id')->get();

        return view('admin.phase.index', [
            'enVigueur' => $decisions->firstWhere('statut', DecisionPhase::CONFIRMEE),
            'presentation' => Phase::presentationActions(),
            'souscription' => Phase::souscriptionParametre(),
            'tranche' => Phase::trancheOuverte(),
            'enAttente' => $decisions->firstWhere('statut', DecisionPhase::SOUMISE),
            'decisions' => $decisions,
        ]);
    }

    public function proposer(Request $request): RedirectResponse
    {
        $donnees = $request->validate([
            'presentation_actions' => ['required', 'boolean'],
            'souscription_actions' => ['required', 'boolean'],
            'motif' => ['required', 'string', 'min:5', 'max:1000'],
        ], ['motif.*' => __('admin.phase.erreurs.motif')]);
        $presentation = (bool) $donnees['presentation_actions'];
        $souscription = (bool) $donnees['souscription_actions'];

        $erreur = match (true) {
            $souscription && ! $presentation => 'souscription_sans_presentation',
            DecisionPhase::query()->where('statut', DecisionPhase::SOUMISE)->exists() => 'deja',
            $presentation === Phase::presentationActions() && $souscription === Phase::souscriptionParametre() => 'identique',
            default => null,
        };
        if ($erreur) {
            return back()->withInput()->with('erreur', __('admin.phase.erreurs.'.$erreur));
        }

        $decision = DecisionPhase::create([
            'presentation_actions' => $presentation, 'souscription_actions' => $souscription,
            'statut' => DecisionPhase::SOUMISE, 'motif' => $donnees['motif'], 'propose_par' => $request->user()->id,
        ]);
        $this->journal->ecrire($request->user(), 'phase.proposee', 'DecisionPhase', $decision->id, null, $decision->only(['presentation_actions', 'souscription_actions', 'motif']));

        return redirect()->route('admin.phase')->avecSucces('admin.phase.messages.proposee');
    }

    public function confirmer(Request $request, int $decision): RedirectResponse
    {
        return $this->decider($request, $decision, DecisionPhase::CONFIRMEE, null);
    }

    public function rejeter(Request $request, int $decision): RedirectResponse
    {
        $motif = $request->validate(['motif' => ['required', 'string', 'min:5', 'max:1000']], ['motif.*' => __('admin.phase.erreurs.motif')])['motif'];

        return $this->decider($request, $decision, DecisionPhase::REJETEE, $motif);
    }

    /** Proposition retirée par son auteur tant que le VALIDATEUR ne l'a pas traitée. */
    public function retirer(Request $request, int $decision): RedirectResponse
    {
        $erreur = DB::transaction(function () use ($request, $decision) {
            $d = DecisionPhase::query()->lockForUpdate()->findOrFail($decision);
            if ($d->statut !== DecisionPhase::SOUMISE) {
                return 'non_soumise';
            }
            if ($d->propose_par !== $request->user()->id) {
                return 'pas_auteur';
            }
            $d->forceFill(['statut' => DecisionPhase::RETIREE])->save();
            $this->journal->ecrire($request->user(), 'phase.retiree', 'DecisionPhase', $d->id, ['statut' => DecisionPhase::SOUMISE], ['statut' => DecisionPhase::RETIREE]);

            return null;
        });

        return $erreur
            ? redirect()->route('admin.phase')->with('erreur', __('admin.phase.erreurs.'.$erreur))
            : redirect()->route('admin.phase')->avecSucces('admin.phase.messages.retiree');
    }

    private function decider(Request $request, int $id, string $statut, ?string $motif): RedirectResponse
    {
        $erreur = DB::transaction(function () use ($request, $id, $statut, $motif) {
            $decision = DecisionPhase::query()->lockForUpdate()->findOrFail($id);
            if ($decision->statut !== DecisionPhase::SOUMISE) {
                return 'non_soumise';
            }
            if ($decision->propose_par === $request->user()->id) {
                return 'meme_personne';
            }
            $decision->forceFill(['statut' => $statut, 'decide_par' => $request->user()->id, 'decide_le' => now(), 'motif_rejet' => $motif])->save();
            $this->journal->ecrire($request->user(), $statut === DecisionPhase::CONFIRMEE ? 'phase.confirmee' : 'phase.rejetee', 'DecisionPhase', $decision->id,
                ['statut' => DecisionPhase::SOUMISE], ['statut' => $statut, 'motif' => $motif]);
            DB::afterCommit(fn () => $this->notifications->envoyer($decision->propose_par, __('admin.a_valider.elements.phase'), __('admin.phase.titre'),
                $statut === DecisionPhase::CONFIRMEE ? 'confirmer' : 'rejeter', $motif, route('admin.phase')));

            return null;
        });
        Cache::forget(Phase::CACHE);

        // VALIDATEUR (sans l'écran des démarches) : retour sur « À valider › Démarches ».
        $retour = $request->user()->can(Droit::PhaseVoir->value) ? route('admin.phase') : route('admin.a-valider.rubrique', 'demarches');

        return $erreur
            ? redirect($retour)->with('erreur', __('admin.phase.erreurs.'.$erreur))
            : redirect($retour)->avecSucces('admin.phase.messages.'.($statut === DecisionPhase::CONFIRMEE ? 'confirmee' : 'rejetee'));
    }
}
