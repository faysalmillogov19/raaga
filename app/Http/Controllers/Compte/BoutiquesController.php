<?php

namespace App\Http\Controllers\Compte;

use App\Enums\DocumentAccepte;
use App\Enums\Role;
use App\Enums\StatutCampagneBoutiques;
use App\Http\Controllers\Controller;
use App\Models\CampagneBoutiques;
use App\Models\CandidatureBoutique;
use App\Services\Boutiques\CandidatureBoutiques;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/** Boutiques du marché : présentation de la campagne, candidature et suivi (citoyen connecté). */
class BoutiquesController extends Controller
{
    public function __construct(private readonly CandidatureBoutiques $candidatures) {}

    /** Page publique : campagne ouverte (catégories, cautions) ou état vide. */
    public function index(Request $request): View
    {
        $campagne = CampagneBoutiques::ouverte()
            ?? CampagneBoutiques::query()->whereIn('statut', [StatutCampagneBoutiques::Cloturee, StatutCampagneBoutiques::Attribuee])->latest('cloturee_le')->first();
        $user = $request->user();

        return view('public.boutiques.index', [
            'campagne' => $campagne?->load('categories'),
            'ouverte' => $campagne && CandidatureBoutiques::accepteCandidatures($campagne),
            'candidature' => $campagne && $user ? CandidatureBoutiques::active($campagne, $user) : null,
        ]);
    }

    public function reglement(): View
    {
        $campagne = CampagneBoutiques::query()->whereIn('statut', [StatutCampagneBoutiques::Ouverte, StatutCampagneBoutiques::Cloturee, StatutCampagneBoutiques::Attribuee])
            ->latest('id')->first();
        abort_unless($campagne, 404);

        return view('public.boutiques.reglement', ['campagne' => $campagne->load('categories')]);
    }

    public function create(Request $request): View|RedirectResponse
    {
        $campagne = CampagneBoutiques::ouverte();
        abort_unless($campagne && CandidatureBoutiques::accepteCandidatures($campagne), 404);

        if ($existante = CandidatureBoutiques::active($campagne, $request->user())) {
            return redirect()->route('boutiques.candidature', $existante->reference);
        }

        return view('public.boutiques.candidater', [
            'campagne' => $campagne->load('categories'),
            'user' => $request->user(),
            'engagements' => CandidatureBoutiques::ENGAGEMENTS,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $campagne = CampagneBoutiques::ouverte();
        abort_unless($campagne, 404);

        $donnees = $request->validate([
            'categorie' => ['required', 'integer'],
            'engagements' => ['array'],
            'engagements.*' => [Rule::enum(DocumentAccepte::class)],
        ], ['categorie.required' => __('public.boutiques.erreurs.categorie')]);

        $candidature = $this->candidatures->candidater(
            $campagne, $request->user(), (int) $donnees['categorie'], $donnees['engagements'] ?? [],
            (string) $request->ip(), (string) $request->userAgent(),
        );

        return redirect()->route('boutiques.candidature', $candidature->reference)->with('candidature.creee', true);
    }

    /** Suivi d'une candidature : uniquement par son titulaire. */
    public function candidature(Request $request, string $reference): View
    {
        $candidature = CandidatureBoutique::query()->where('reference', $reference)->with(['campagne', 'categorie'])->firstOrFail();
        abort_unless($candidature->user_id === $request->user()->id && $request->user()->hasRole(Role::Citoyen), 403);

        return view('public.boutiques.candidature', ['candidature' => $candidature]);
    }
}
