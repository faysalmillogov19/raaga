<?php

namespace App\Http\Controllers\Admin;

use App\Enums\Droit;
use App\Enums\EtatPhysiqueBoutique;
use App\Enums\NiveauBoutique;
use App\Enums\PositionBoutique;
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
            'sites' => Site::query()->withCount([
                'boutiques' => fn ($q) => $q->where('statut', '!=', StatutBoutique::Retiree),
                'boutiquesSansNiveau',
            ])->orderBy('nom')->get(),
            'types' => TypeBoutique::query()->withCount('boutiques')->orderBy('ordre')->orderBy('libelle')->get(),
        ]);
    }

    // ------------------------------------------------------------------ Types de boutiques

    public function ajouterType(Request $request): RedirectResponse
    {
        Gate::authorize(Droit::SitesGerer->value);
        $this->majuscules($request, ['code']);
        $donnees = $request->validate([
            'code' => $this->regleCodeType(),
            'libelle' => ['required', 'string', 'max:80', Rule::unique('types_boutiques', 'libelle')],
            'description' => ['nullable', 'string', 'max:300'],
        ], [], __('admin.sites.types.attributs'));

        $type = TypeBoutique::create([...$donnees, 'ordre' => (int) TypeBoutique::max('ordre') + 1]);
        $this->journal->ecrire($request->user(), 'type_boutique.cree', 'TypeBoutique', $type->id, null, ['code' => $type->code, 'libelle' => $type->libelle]);

        return redirect()->route('admin.sites')->with('succes', __('admin.sites.types.ajoute'));
    }

    /** Code d'un type (type saisi avant les codes, ou correction). */
    public function modifierType(Request $request, TypeBoutique $type): RedirectResponse
    {
        Gate::authorize(Droit::SitesGerer->value);
        $this->majuscules($request, ['code']);
        $code = $request->validate(['code' => $this->regleCodeType($type)], [], __('admin.sites.types.attributs'))['code'];

        $this->journal->ecrire($request->user(), 'type_boutique.modifie', 'TypeBoutique', $type->id, ['code' => $type->code], ['code' => $code]);
        $type->forceFill(['code' => $code])->save();

        return redirect()->route('admin.sites')->with('succes', __('admin.sites.types.modifie'));
    }

    public function supprimerType(Request $request, TypeBoutique $type): RedirectResponse
    {
        Gate::authorize(Droit::SitesGerer->value);

        if ($type->boutiques()->withTrashed()->exists()) {
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
        $this->journal->ecrire($request->user(), 'site.cree', 'Site', $site->id, null, ['code' => $site->code, 'nom' => $site->nom]);

        return redirect()->route('admin.sites.show', $site)->with('succes', __('admin.sites.messages.enregistre'));
    }

    public function show(Request $request, Site $site): View
    {
        Gate::authorize(Droit::SitesVoir->value);

        $versions = $site->versions()->with('auteur')->get();
        $soumise = $versions->last();
        $precedente = $versions->count() > 1 ? $versions->get($versions->count() - 2) : null;

        $differentiel = $site->estSoumis() && $soumise ? WorkflowParametrage::differentiel($precedente?->parametres ?? [], $soumise->parametres) : [];
        if (isset($differentiel['fiches'])) {
            // Fiches : seules les boutiques ajoutées, modifiées ou retirées sont listées.
            [$avant, $apres] = array_map(fn ($f) => (array) $f, $differentiel['fiches']);
            $modifiees = collect(array_keys($avant + $apres))->filter(fn ($code) => ($avant[$code] ?? null) !== ($apres[$code] ?? null))->sort(SORT_NATURAL);
            $differentiel['fiches'] = [null, $modifiees->take(30)->implode(', ').($modifiees->count() > 30 ? '…' : '')];
        }

        return view('admin.sites.show', [
            'site' => $site,
            'boutiques' => $site->boutiques()->with('type')->get(),
            'types' => TypeBoutique::query()->orderBy('ordre')->orderBy('libelle')->get(),
            'versions' => $versions->reverse()->values(),
            'decisions' => ParametrageDecision::query()->where('objet_type', 'site')->where('objet_id', $site->id)->with('decideur')->orderByDesc('id')->get(),
            'differentiel' => $differentiel,
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
        $donnees = $this->valider($request, $site);

        return $this->executer($site, function () use ($request, $site, $donnees) {
            $this->exigerBrouillon($site);
            $site->fill($donnees)->save();
            $this->journal->ecrire($request->user(), 'site.modifie', 'Site', $site->id);
        }, 'admin.sites.messages.enregistre');
    }

    // ------------------------------------------------------------------ Boutiques du site

    public function ajouterBoutique(Request $request, Site $site): RedirectResponse
    {
        Gate::authorize(Droit::SitesGerer->value);
        $this->majuscules($request, ['bloc']);
        $donnees = $request->validate([
            ...$this->reglesCode(),
            'numero' => $this->regleNumero(),
            ...$this->reglesFiche(),
        ], [], __('admin.sites.boutiques.attributs'));

        return $this->executer($site, function () use ($request, $site, $donnees) {
            $this->exigerBrouillon($site);
            $this->exigerCodeSite($site);
            $boutique = $site->boutiques()->create([...$donnees, 'numero' => $this->numero($donnees['numero']), 'statut' => StatutBoutique::Disponible]);
            $this->journal->ecrire($request->user(), 'boutique.creee', 'Boutique', $boutique->id, null, ['site' => $site->id, 'code' => $boutique->code]);
        }, 'admin.sites.boutiques.ajoutee');
    }

    /** Série : même niveau et même bloc, numéros du début à la fin (RAA-R1-A-001 à RAA-R1-A-040). */
    public function genererBoutiques(Request $request, Site $site): RedirectResponse
    {
        Gate::authorize(Droit::SitesGerer->value);
        $this->majuscules($request, ['bloc']);
        $donnees = $request->validate([
            ...$this->reglesCode(),
            'debut' => $this->regleNumero(),
            'fin' => [...$this->regleNumero(), 'gte:debut'],
            ...$this->reglesFiche(),
        ], [], __('admin.sites.boutiques.attributs'));

        $numeros = array_map(fn (int $n) => $this->numero($n), range($donnees['debut'], $donnees['fin']));
        $codes = array_map(fn (string $n) => Boutique::composerCode($site->code, $donnees['niveau'], $donnees['bloc'], $n), $numeros);
        // Un code déjà attribué, même à une boutique supprimée, n'est jamais réutilisé.
        $pris = Boutique::withTrashed()->whereIn('code', array_filter($codes))->pluck('code');
        if ($pris->isNotEmpty()) {
            return back()->withInput()->withErrors(['debut' => __('admin.sites.boutiques.existants', ['codes' => $pris->sort(SORT_NATURAL)->take(5)->implode(', ')])]);
        }
        $fiche = collect($donnees)->except(['debut', 'fin'])->all();

        return $this->executer($site, function () use ($request, $site, $fiche, $numeros, $codes) {
            $this->exigerBrouillon($site);
            $this->exigerCodeSite($site);
            DB::transaction(function () use ($site, $fiche, $numeros) {
                foreach ($numeros as $numero) {
                    $site->boutiques()->create([...$fiche, 'numero' => $numero, 'statut' => StatutBoutique::Disponible]);
                }
            });
            $this->journal->ecrire($request->user(), 'boutiques.generees', 'Site', $site->id, null, ['nombre' => count($codes), 'de' => $codes[0], 'a' => end($codes)]);
        }, 'admin.sites.boutiques.generees');
    }

    /** Fiche d'une boutique : niveau, bloc et numéro ne se saisissent que tant que le code manque (boutiques saisies avant les codes). */
    public function editBoutique(Site $site, Boutique $boutique): View|RedirectResponse
    {
        Gate::authorize(Droit::SitesGerer->value);
        abort_unless($boutique->site_id === $site->id, 404);

        if (! $site->estModifiable()) {
            return redirect()->route('admin.sites.show', $site)->with('erreur', __('admin.parametrage.regles.non_modifiable'));
        }
        if ($boutique->statut->verrouillee()) {
            return redirect()->route('admin.sites.show', $site)->with('erreur', __('admin.sites.boutiques.verrouillee'));
        }

        return view('admin.sites.boutique', [
            'site' => $site,
            'boutique' => $boutique,
            'types' => TypeBoutique::query()->orderBy('ordre')->orderBy('libelle')->get(),
        ]);
    }

    public function updateBoutique(Request $request, Site $site, Boutique $boutique): RedirectResponse
    {
        Gate::authorize(Droit::SitesGerer->value);
        abort_unless($boutique->site_id === $site->id, 404);
        $this->majuscules($request, ['bloc']);
        $donnees = $request->validate([
            ...($boutique->code === null ? [...$this->reglesCode(), 'numero' => $this->regleNumero()] : []),
            ...$this->reglesFiche(),
        ], [], __('admin.sites.boutiques.attributs'));
        if (isset($donnees['numero'])) {
            $donnees['numero'] = $this->numero($donnees['numero']);
        }

        return $this->executer($site, function () use ($request, $site, $boutique, $donnees) {
            $this->exigerBrouillon($site);
            if ($boutique->statut->verrouillee()) {
                throw new RegleGestionException(__('admin.sites.boutiques.verrouillee'));
            }
            $avant = $boutique->only(array_keys($donnees));
            $boutique->fill($donnees)->save();
            $this->journal->ecrire($request->user(), 'boutique.modifiee', 'Boutique', $boutique->id, $avant, [...$boutique->only(array_keys($donnees)), 'code' => $boutique->code]);
        }, 'admin.sites.boutiques.modifiee');
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
            // Suppression logique : la boutique garde son code, qui ne sera jamais réutilisé.
            $this->journal->ecrire($request->user(), 'boutique.supprimee', 'Boutique', $boutique->id, ['site' => $site->id, 'code' => $boutique->reference()]);
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
            $this->exigerCodeSite($site);
            if (($sansNiveau = $site->boutiquesSansNiveau()->count()) > 0) {
                throw new RegleGestionException(trans_choice('admin.sites.regles.sans_niveau', $sansNiveau, ['n' => $sansNiveau]));
            }
            if (($sansCode = $site->boutiques()->whereNull('code')->where('statut', '!=', StatutBoutique::Retiree)->count()) > 0) {
                throw new RegleGestionException(trans_choice('admin.sites.regles.sans_code', $sansCode, ['n' => $sansCode]));
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
    private function valider(Request $request, ?Site $site = null): array
    {
        $this->majuscules($request, ['code']);

        return $request->validate([
            'code' => ['required', 'string', 'regex:'.Site::FORMAT_CODE, Rule::unique('sites', 'code')->ignore($site?->id)],
            'nom' => ['required', 'string', 'max:150'],
            'region' => ['required', Rule::in(config('raaga.inscription.regions'))],
            'commune' => ['required', 'string', 'max:80'],
            'adresse' => ['required', 'string', 'max:300'],
            'description' => ['nullable', 'string', 'max:2000'],
        ], [], __('admin.sites.champs'));
    }

    /** @return list<mixed> */
    private function regleCodeType(?TypeBoutique $type = null): array
    {
        return ['required', 'string', 'regex:'.TypeBoutique::FORMAT_CODE, Rule::unique('types_boutiques', 'code')->ignore($type?->id)];
    }

    /** Éléments du code boutique saisis par le gestionnaire, hors numéro. @return array<string, list<mixed>> */
    private function reglesCode(): array
    {
        return [
            'niveau' => ['required', Rule::enum(NiveauBoutique::class)],
            'bloc' => ['required', 'string', 'regex:'.Boutique::FORMAT_BLOC],
        ];
    }

    /** @return list<mixed> */
    private function regleNumero(): array
    {
        return ['required', 'integer', 'min:1', 'max:'.str_repeat('9', Boutique::CHIFFRES_NUMERO)];
    }

    /** @return array<string, list<mixed>> */
    private function reglesFiche(): array
    {
        return [
            'surface' => ['nullable', 'numeric', 'min:0.5', 'max:99999'],
            'type_boutique_id' => ['required', Rule::exists('types_boutiques', 'id')],
            'position' => ['nullable', Rule::enum(PositionBoutique::class)],
            'coffret' => ['nullable', 'string', 'max:60'],
            'planche' => ['nullable', 'string', 'max:60'],
            'etat_physique' => ['required', Rule::enum(EtatPhysiqueBoutique::class)],
        ];
    }

    private function numero(int|string $numero): string
    {
        return str_pad((string) (int) $numero, Boutique::CHIFFRES_NUMERO, '0', STR_PAD_LEFT);
    }

    /** Codes saisis en minuscules ou avec des espaces : normalisés avant validation. */
    private function majuscules(Request $request, array $champs): void
    {
        foreach ($champs as $champ) {
            if (is_string($request->input($champ))) {
                $request->merge([$champ => mb_strtoupper(trim($request->input($champ)))]);
            }
        }
    }

    /** @throws RegleGestionException */
    private function exigerCodeSite(Site $site): void
    {
        if ($site->code === null) {
            throw new RegleGestionException(__('admin.sites.regles.sans_code_site'));
        }
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
