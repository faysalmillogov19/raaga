<?php

namespace App\Http\Controllers\Admin;

use App\Enums\Droit;
use App\Enums\StatutBoutique;
use App\Enums\StatutSite;
use App\Http\Controllers\Controller;
use App\Models\Boutique;
use App\Models\ParametrageDecision;
use App\Models\Site;
use App\Models\TypeBoutique;
use App\Services\Audit\Journal;
use App\Services\Backoffice\RegleGestionException;
use App\Services\Parametrage\WorkflowParametrage;
use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Référentiel des sites du marché et de leurs boutiques, tenu en amont des campagnes.
 * GESTIONNAIRE DES SITES ET BOUTIQUES : saisie et soumission ; VALIDATEUR : confirmation ou rejet ; autres profils : lecture.
 */
class SitesController extends Controller
{
    public function __construct(private readonly WorkflowParametrage $workflow, private readonly Journal $journal) {}

    public function index(): View
    {
        Gate::authorize(Droit::SitesVoir->value);

        return view('admin.sites.index', [
            'sites' => Site::query()->withCount(['boutiques' => fn ($q) => $q->where('statut', '!=', StatutBoutique::Retiree)])->orderBy('nom')->get(),
            'types' => TypeBoutique::query()->withCount('boutiques')->orderBy('ordre')->orderBy('libelle')->get(),
        ]);
    }

    // ------------------------------------------------------------------ Types de boutiques

    public function ajouterType(Request $request): RedirectResponse
    {
        Gate::authorize(Droit::SitesGerer->value);
        $donnees = $request->validate([
            'libelle' => ['required', 'string', 'max:80', Rule::unique('types_boutiques', 'libelle')],
            'description' => ['nullable', 'string', 'max:300'],
        ], [], __('admin.sites.types.attributs'));

        $type = TypeBoutique::create([...$donnees, 'ordre' => (int) TypeBoutique::max('ordre') + 1]);
        $this->journal->ecrire($request->user(), 'type_boutique.cree', 'TypeBoutique', $type->id, null, ['libelle' => $type->libelle]);

        return redirect()->route('admin.sites')->with('succes', __('admin.sites.types.ajoute'));
    }

    public function supprimerType(Request $request, TypeBoutique $type): RedirectResponse
    {
        Gate::authorize(Droit::SitesGerer->value);

        if ($type->boutiques()->exists()) {
            return redirect()->route('admin.sites')->with('erreur', __('admin.sites.types.utilise'));
        }
        $this->journal->ecrire($request->user(), 'type_boutique.supprime', 'TypeBoutique', $type->id, ['libelle' => $type->libelle]);
        $type->delete();

        return redirect()->route('admin.sites')->with('succes', __('admin.sites.types.supprime'));
    }

    // ------------------------------------------------------------------ Sites

    public function create(): View
    {
        Gate::authorize(Droit::SitesGerer->value);

        return view('admin.sites.formulaire', ['site' => new Site]);
    }

    public function store(Request $request): RedirectResponse
    {
        Gate::authorize(Droit::SitesGerer->value);

        $site = Site::create([...$this->valider($request), 'statut' => StatutSite::Brouillon, 'cree_par' => $request->user()->id]);
        $this->journal->ecrire($request->user(), 'site.cree', 'Site', $site->id, null, ['nom' => $site->nom]);

        return redirect()->route('admin.sites.show', $site)->with('succes', __('admin.sites.messages.enregistre'));
    }

    public function show(Request $request, Site $site): View
    {
        Gate::authorize(Droit::SitesVoir->value);

        $versions = $site->versions()->with('auteur')->get();
        $soumise = $versions->last();
        $precedente = $versions->count() > 1 ? $versions->get($versions->count() - 2) : null;

        return view('admin.sites.show', [
            'site' => $site,
            'boutiques' => $site->boutiques()->with('type')->get(),
            'types' => TypeBoutique::query()->orderBy('ordre')->orderBy('libelle')->get(),
            'versions' => $versions->reverse()->values(),
            'decisions' => ParametrageDecision::query()->where('objet_type', 'site')->where('objet_id', $site->id)->with('decideur')->orderByDesc('id')->get(),
            'differentiel' => $site->estSoumis() && $soumise ? WorkflowParametrage::differentiel($precedente?->parametres ?? [], $soumise->parametres) : [],
            'premiereVersion' => $precedente === null,
        ]);
    }

    public function edit(Site $site): View|RedirectResponse
    {
        Gate::authorize(Droit::SitesGerer->value);

        return $site->estModifiable()
            ? view('admin.sites.formulaire', ['site' => $site])
            : redirect()->route('admin.sites.show', $site)->with('erreur', __('admin.parametrage.regles.non_modifiable'));
    }

    public function update(Request $request, Site $site): RedirectResponse
    {
        Gate::authorize(Droit::SitesGerer->value);

        return $this->executer($site, function () use ($request, $site) {
            $this->exigerBrouillon($site);
            $site->fill($this->valider($request))->save();
            $this->journal->ecrire($request->user(), 'site.modifie', 'Site', $site->id);
        }, 'admin.sites.messages.enregistre');
    }

    // ------------------------------------------------------------------ Boutiques du site

    public function ajouterBoutique(Request $request, Site $site): RedirectResponse
    {
        Gate::authorize(Droit::SitesGerer->value);
        $donnees = $request->validate([
            'numero' => ['required', 'string', 'max:30', Rule::unique('boutiques', 'numero')->where('site_id', $site->id)],
            'bloc' => ['nullable', 'string', 'max:40'],
            'surface' => ['nullable', 'numeric', 'min:0.5', 'max:99999'],
            'type_boutique_id' => ['required', Rule::exists('types_boutiques', 'id')],
        ], [], __('admin.sites.boutiques.attributs'));

        return $this->executer($site, function () use ($request, $site, $donnees) {
            $this->exigerBrouillon($site);
            $boutique = $site->boutiques()->create([...$donnees, 'statut' => StatutBoutique::Disponible]);
            $this->journal->ecrire($request->user(), 'boutique.creee', 'Boutique', $boutique->id, null, ['site' => $site->id, 'numero' => $boutique->numero]);
        }, 'admin.sites.boutiques.ajoutee');
    }

    /** Série numérotée : préfixe + numéros du début à la fin (« A-001 » à « A-040 »). */
    public function genererBoutiques(Request $request, Site $site): RedirectResponse
    {
        Gate::authorize(Droit::SitesGerer->value);
        $donnees = $request->validate([
            'prefixe' => ['nullable', 'string', 'max:20'],
            'debut' => ['required', 'integer', 'min:0', 'max:99999'],
            'fin' => ['required', 'integer', 'gte:debut', 'max:99999'],
            'chiffres' => ['required', 'integer', 'min:1', 'max:5'],
            'bloc' => ['nullable', 'string', 'max:40'],
            'surface' => ['nullable', 'numeric', 'min:0.5', 'max:99999'],
            'type_boutique_id' => ['required', Rule::exists('types_boutiques', 'id')],
        ], [], __('admin.sites.boutiques.attributs'));
        if ($donnees['fin'] - $donnees['debut'] >= 1000) {
            return back()->withInput()->withErrors(['fin' => __('admin.sites.boutiques.serie_trop_longue')]);
        }

        $numeros = array_map(fn (int $n) => ($donnees['prefixe'] ?? '').str_pad((string) $n, $donnees['chiffres'], '0', STR_PAD_LEFT), range($donnees['debut'], $donnees['fin']));
        $existants = $site->boutiques()->whereIn('numero', $numeros)->pluck('numero');
        if ($existants->isNotEmpty()) {
            return back()->withInput()->withErrors(['debut' => __('admin.sites.boutiques.existants', ['numeros' => $existants->take(5)->implode(', ')])]);
        }

        return $this->executer($site, function () use ($request, $site, $donnees, $numeros) {
            $this->exigerBrouillon($site);
            DB::transaction(function () use ($site, $donnees, $numeros) {
                foreach ($numeros as $numero) {
                    $site->boutiques()->create([
                        'numero' => $numero, 'bloc' => $donnees['bloc'] ?? null, 'surface' => $donnees['surface'] ?? null,
                        'type_boutique_id' => $donnees['type_boutique_id'], 'statut' => StatutBoutique::Disponible,
                    ]);
                }
            });
            $this->journal->ecrire($request->user(), 'boutiques.generees', 'Site', $site->id, null, ['nombre' => count($numeros), 'de' => $numeros[0], 'a' => end($numeros)]);
        }, 'admin.sites.boutiques.generees');
    }

    public function supprimerBoutique(Request $request, Site $site, Boutique $boutique): RedirectResponse
    {
        Gate::authorize(Droit::SitesGerer->value);
        abort_unless($boutique->site_id === $site->id, 404);

        return $this->executer($site, function () use ($request, $site, $boutique) {
            $this->exigerBrouillon($site);
            if ($boutique->statut->verrouillee()) {
                throw new RegleGestionException(__('admin.sites.boutiques.verrouillee'));
            }
            $this->journal->ecrire($request->user(), 'boutique.supprimee', 'Boutique', $boutique->id, ['site' => $site->id, 'numero' => $boutique->numero]);
            $boutique->delete();
        }, 'admin.sites.boutiques.supprimee');
    }

    // ------------------------------------------------------------------ Workflow

    public function soumettre(Request $request, Site $site): RedirectResponse
    {
        Gate::authorize(Droit::SitesGerer->value);

        return $this->executer($site, function () use ($request, $site) {
            if (! $site->boutiques()->exists()) {
                throw new RegleGestionException(__('admin.sites.regles.sans_boutique'));
            }
            $this->workflow->soumettre($site, $request->user());
        }, 'admin.parametrage.messages.soumis');
    }

    public function confirmer(Request $request, Site $site): RedirectResponse
    {
        Gate::authorize(Droit::SitesConfirmer->value);

        return $this->executer($site, fn () => $this->workflow->confirmer($site, $request->user()), 'admin.parametrage.messages.confirme');
    }

    public function rejeter(Request $request, Site $site): RedirectResponse
    {
        Gate::authorize(Droit::SitesConfirmer->value);
        $motif = $request->validate(['motif' => ['required', 'string', 'min:5', 'max:1000']], ['motif.*' => __('admin.utilisateurs.erreurs.motif')])['motif'];

        return $this->executer($site, fn () => $this->workflow->rejeter($site, $request->user(), $motif), 'admin.parametrage.messages.rejete');
    }

    /** Remise en révision d'un site confirmé (ajout de boutiques…) : il devra être soumis et confirmé de nouveau. */
    public function reviser(Request $request, Site $site): RedirectResponse
    {
        Gate::authorize(Droit::SitesGerer->value);

        return $this->executer($site, function () use ($request, $site) {
            if ($site->statut !== StatutSite::Confirme) {
                throw new RegleGestionException(__('admin.sites.regles.revision_confirme'));
            }
            $site->forceFill(['statut' => StatutSite::Brouillon])->save();
            $this->journal->ecrire($request->user(), 'site.revision', 'Site', $site->id, ['statut' => 'CONFIRME'], ['statut' => 'BROUILLON']);
        }, 'admin.sites.messages.revision');
    }

    /** @return array<string, mixed> */
    private function valider(Request $request): array
    {
        return $request->validate([
            'nom' => ['required', 'string', 'max:150'],
            'region' => ['required', Rule::in(config('raaga.inscription.regions'))],
            'commune' => ['required', 'string', 'max:80'],
            'adresse' => ['required', 'string', 'max:300'],
            'description' => ['nullable', 'string', 'max:2000'],
        ], [], __('admin.sites.champs'));
    }

    /** @throws RegleGestionException */
    private function exigerBrouillon(Site $site): void
    {
        if (! $site->estModifiable()) {
            throw new RegleGestionException(__('admin.parametrage.regles.non_modifiable'));
        }
    }

    private function executer(Site $site, Closure $action, string $message): RedirectResponse
    {
        try {
            $action();
        } catch (RegleGestionException $e) {
            return redirect()->route('admin.sites.show', $site)->with('erreur', $e->getMessage());
        }

        return redirect()->route('admin.sites.show', $site)->with('succes', __($message));
    }
}
