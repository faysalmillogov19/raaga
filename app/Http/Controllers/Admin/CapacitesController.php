<?php

namespace App\Http\Controllers\Admin;

use App\Enums\Droit;
use App\Http\Controllers\Controller;
use App\Models\CapaciteSite;
use App\Models\Niveau;
use App\Models\Site;
use App\Models\TypeBoutique;
use App\Models\ZoneSite;
use App\Services\Audit\Journal;
use App\Services\Sites\ComparaisonCapacites;
use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Capacités prévues : tableau de toutes les capacités (filtre par colonne), comparaison avec les boutiques importées
 * pour le site choisi. Le GESTIONNAIRE DES SITES ET BOUTIQUES ajoute, modifie ou supprime une capacité d'un site en
 * brouillon ou en révision, avec les mêmes règles que l'import des sites (niveau, zone et type utilisables, surfaces
 * positives, une seule capacité par site, niveau, zone et type).
 */
class CapacitesController extends Controller
{
    public function __construct(private readonly Journal $journal) {}

    public function index(Request $request, ComparaisonCapacites $comparaison): View
    {
        Gate::authorize(Droit::SitesVoir->value);
        $sites = Site::query()->withCount('capacites')->orderBy('code')->get();
        $site = $sites->firstWhere('id', (int) $request->query('site')) ?? $sites->firstWhere('capacites_count', '>', 0) ?? $sites->first();

        return view('admin.referencement.capacites', [
            'sites' => $sites,
            'site' => $site,
            'filtreSite' => $request->query('site') ? $site : null,
            'toutes' => CapaciteSite::query()->with(['site', 'type', 'niveauRef'])->get()
                ->sortBy(fn (CapaciteSite $c) => [$c->site->code ?? $c->site->nom, $c->niveauRef?->rang ?? 99, $c->zone ?? '', $c->type->ordre])->values(),
            'capacites' => $site?->capacites()->with('type')->get() ?? collect(),
            'comparaison' => $site ? $comparaison->pour($site) : collect(),
            'sitesModifiables' => $this->sitesModifiables(),
            'listes' => $this->listes(),
            'capaciteModifiee' => $modifiee = $request->integer('modifier') && Gate::allows(Droit::SitesGerer->value)
                ? CapaciteSite::query()->with('site')->find($request->integer('modifier')) : null,
            'listesModifiee' => $modifiee ? $this->listes($modifiee) : null,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        Gate::authorize(Droit::SitesGerer->value);
        $donnees = $this->valider($request, null);
        $capacite = CapaciteSite::create($donnees);
        $this->journal->ecrire($request->user(), 'capacite.creee', 'CapaciteSite', $capacite->id, null, $donnees);

        return redirect()->route('admin.referencement.capacites', ['site' => $capacite->site_id])->with('succes', __('admin.referencement.capacites.messages.ajoutee'));
    }

    /** Fenêtre de modification (en-tête X-Fenetre) ; sans JavaScript, la liste avec cette fenêtre ouverte. */
    public function edit(Request $request, CapaciteSite $capacite): View|RedirectResponse
    {
        Gate::authorize(Droit::SitesGerer->value);
        if (! $capacite->site->estModifiable()) {
            return $this->retour($capacite->site)->with('erreur', __('admin.referencement.capacites.site_fige'));
        }

        return $request->hasHeader('X-Fenetre')
            ? view('admin.referencement._fenetre-capacite', ['capacite' => $capacite->load(['site', 'type']), 'listes' => $this->listes($capacite), 'ouvrir' => true])
            : redirect()->route('admin.referencement.capacites', ['site' => $capacite->site_id, 'modifier' => $capacite->id]);
    }

    public function update(Request $request, CapaciteSite $capacite): RedirectResponse
    {
        Gate::authorize(Droit::SitesGerer->value);
        if (! $capacite->site->estModifiable()) {
            return $this->retour($capacite->site)->with('erreur', __('admin.referencement.capacites.site_fige'));
        }
        $donnees = $this->valider($request, $capacite);
        $avant = $capacite->only(array_keys($donnees));
        $capacite->fill($donnees)->save();
        $this->journal->ecrire($request->user(), 'capacite.modifiee', 'CapaciteSite', $capacite->id, $avant, $donnees);

        return $this->retour($capacite->site)->with('succes', __('admin.referencement.capacites.messages.modifiee'));
    }

    public function destroy(Request $request, CapaciteSite $capacite): RedirectResponse
    {
        Gate::authorize(Droit::SitesGerer->value);
        $site = $capacite->site;
        if (! $site->estModifiable()) {
            return $this->retour($site)->with('erreur', __('admin.referencement.capacites.site_fige'));
        }
        $this->journal->ecrire($request->user(), 'capacite.supprimee', 'CapaciteSite', $capacite->id,
            $capacite->only(['site_id', 'niveau', 'zone', 'type_boutique_id', 'nombre_prevu', 'surface_unitaire', 'surface_totale']));
        $capacite->delete();

        return $this->retour($site)->with('succes', __('admin.referencement.capacites.messages.supprimee'));
    }

    /** Sites dont les capacités se modifient : brouillon ou révision. */
    private function sitesModifiables(): Collection
    {
        return Site::query()->orderBy('code')->get()->filter->estModifiable()->values();
    }

    /** Niveaux, zones et types proposés : utilisables, plus la valeur actuelle de la capacité modifiée. */
    private function listes(?CapaciteSite $capacite = null): array
    {
        return [
            'niveaux' => Niveau::query()->where(fn ($q) => $q->utilisables()->orWhere('code', $capacite?->niveau ?? ''))->orderBy('rang')->get(),
            'zones' => ZoneSite::query()->where(fn ($q) => $q->utilisables()->orWhere('code', $capacite?->zone ?? ''))->orderBy('ordre')->get(),
            'types' => TypeBoutique::query()->where(fn ($q) => $q->utilisables()->orWhere('id', $capacite?->type_boutique_id ?? 0))->orderBy('ordre')->orderBy('libelle')->get(),
        ];
    }

    /** @return array<string, mixed> */
    private function valider(Request $request, ?CapaciteSite $capacite): array
    {
        foreach (['niveau', 'zone'] as $champ) {
            if (is_string($request->input($champ))) {
                $request->merge([$champ => mb_strtoupper(trim($request->input($champ))) ?: null]);
            }
        }
        $listes = $this->listes($capacite);
        $donnees = $request->validate([
            'site_id' => $capacite ? ['prohibited'] : ['required', 'integer', fn (string $a, mixed $v, Closure $echec) => $this->sitesModifiables()->contains('id', (int) $v)
                ? null : $echec(__('admin.referencement.capacites.site_fige'))],
            'niveau' => ['nullable', 'string', fn (string $a, mixed $v, Closure $echec) => $listes['niveaux']->contains('code', $v) ? null : $echec(__('admin.referencement.capacites.erreurs.niveau'))],
            'zone' => ['nullable', 'string', fn (string $a, mixed $v, Closure $echec) => $listes['zones']->contains('code', $v) ? null : $echec(__('admin.referencement.capacites.erreurs.zone'))],
            'type_boutique_id' => ['required', 'integer', fn (string $a, mixed $v, Closure $echec) => $listes['types']->contains('id', (int) $v) ? null : $echec(__('admin.referencement.capacites.erreurs.type'))],
            'nombre_prevu' => ['nullable', 'integer', 'min:0', 'max:99999'],
            'surface_unitaire' => ['nullable', 'numeric', 'gt:0', 'max:999999'],
            'surface_totale' => ['nullable', 'numeric', 'gt:0', 'max:9999999'],
            'source' => ['nullable', 'string', 'max:200'],
            'observations' => ['nullable', 'string', 'max:500'],
        ], [], __('admin.referencement.capacites.champs'));

        // Une seule capacité par site, niveau, zone et type (comme à l'import).
        $siteId = $capacite?->site_id ?? (int) $donnees['site_id'];
        $doublon = CapaciteSite::query()->where('site_id', $siteId)->where('niveau', $donnees['niveau'] ?? null)->where('zone', $donnees['zone'] ?? null)
            ->where('type_boutique_id', $donnees['type_boutique_id'])->when($capacite, fn ($q) => $q->whereKeyNot($capacite->id))->exists();
        if ($doublon) {
            throw ValidationException::withMessages(['type_boutique_id' => __('admin.referencement.capacites.erreurs.doublon')]);
        }

        // Un champ vidé dans le formulaire est effacé.
        $donnees = [...array_fill_keys(['niveau', 'zone', 'nombre_prevu', 'surface_unitaire', 'surface_totale', 'source', 'observations'], null), ...$donnees];

        return $capacite ? $donnees : [...$donnees, 'site_id' => $siteId];
    }

    private function retour(Site $site): RedirectResponse
    {
        return redirect()->route('admin.referencement.capacites', ['site' => $site->id]);
    }
}
