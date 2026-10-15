<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\DecisionPhase;
use App\Services\Audit\Journal;
use App\Support\Phase;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Démarches du site public : « présentation des actions visible » et « souscription d'actions ouverte ».
 * Le SUPER_ADMIN propose, le VALIDATEUR confirme ou rejette (quatre yeux) ; chaque décision reste dans l'historique
 * et au journal d'audit. Le site public applique la dernière décision confirmée.
 */
class PhaseController extends Controller
{
    public function __construct(private readonly Journal $journal) {}

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

        return redirect()->route('admin.phase')->with('succes', __('admin.phase.messages.proposee'));
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

            return null;
        });
        Cache::forget(Phase::CACHE);

        return $erreur
            ? redirect()->route('admin.phase')->with('erreur', __('admin.phase.erreurs.'.$erreur))
            : redirect()->route('admin.phase')->with('succes', __('admin.phase.messages.'.($statut === DecisionPhase::CONFIRMEE ? 'confirmee' : 'rejetee')));
    }
}
