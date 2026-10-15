<?php

namespace App\Http\Controllers\Admin;

use App\Enums\Droit;
use App\Enums\EtatPhysiqueBoutique;
use App\Enums\PhaseSite;
use App\Enums\PositionBoutique;
use App\Enums\StatutBoutique;
use App\Enums\StatutSite;
use App\Http\Controllers\Admin\Concerns\EnregistreEtSoumet;
use App\Http\Controllers\Controller;
use App\Models\Boutique;
use App\Models\Niveau;
use App\Models\ParametrageDecision;
use App\Models\Site;
use App\Models\TypeBoutique;
use App\Services\Audit\Journal;
use App\Services\Backoffice\RegleGestionException;
use App\Services\Parametrage\WorkflowParametrage;
use App\Services\Sites\ComparaisonCapacites;
use App\Services\Validation\NotificationDecision;
use App\Support\BadgeStatut;
use App\Support\FicheSite;
use App\Support\Tableau\Colonne;
use App\Support\Tableau\Filtre;
use App\Support\Tableau\ResultatTableau;
use App\Support\Tableau\Tableau;
use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\HtmlString;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Référentiel des sites du marché et de leurs boutiques, tenu en amont des campagnes.
 * GESTIONNAIRE DES SITES ET BOUTIQUES : saisie et soumission ; VALIDATEUR : confirmation ou rejet ; autres profils : lecture.
 */
class SitesController extends Controller
{
    use EnregistreEtSoumet;

    public function __construct(private readonly WorkflowParametrage $workflow, private readonly Journal $journal, private readonly NotificationDecision $notifications) {}

    /** Liste des sites : tableau de données (recherche, filtres, tri, pagination, export) ; coût total réservé aux profils financiers. */
    public function index(Request $request): Response|StreamedResponse|View
    {
        Gate::authorize(Droit::SitesVoir->value);
        $c = __('admin.sites.colonnes');
        $statut = fn (Site $s) => new HtmlString(view('admin.parametrage._statut', ['statut' => $s->statut])->render());

        return Tableau::pour(Site::query(), 'sites')
            ->colonnes([
                Colonne::make('code', $c['code'])->triable()->recherchable()->classe('font-mono font-semibold'),
                Colonne::make('nom', $c['nom'])->triable()->recherchable()
                    ->valeur(fn (Site $s) => new HtmlString('<a href="'.e(route('admin.sites.show', $s)).'" class="font-bold text-vert-fonce underline underline-offset-2">'.e($s->nom).'</a>'))
                    ->texte(fn (Site $s) => $s->nom),
                Colonne::make('lieu', $c['lieu'])->triable('ville')->recherchable(['ville', 'commune', 'arrondissement'])
                    ->valeur(fn (Site $s) => trim(($s->localite() ?? '—').($s->arrondissement ? ' · '.$s->arrondissement : ''))),
                Colonne::make('phase', $c['phase'])->triable()->valeur(fn (Site $s) => $s->phase?->label()),
                Colonne::make('statut', $c['statut'])->triable()->valeur($statut)->texte(fn (Site $s) => $s->statut->label()),
                Colonne::make('boutiques', $c['boutiques'])->numerique()->triable('boutiques_count')
                    ->avec(fn ($q) => $q->withCount(['boutiques' => fn ($b) => $b->where('statut', '!=', StatutBoutique::Retiree), 'boutiquesSansNiveau']))
                    ->valeur(fn (Site $s) => new HtmlString(e(format_nombre($s->boutiques_count)).($s->boutiques_sans_niveau_count > 0
                        ? '<br><span class="text-sm font-semibold text-rouge">'.e(trans_choice('admin.sites.boutiques.sans_niveau_alerte', $s->boutiques_sans_niveau_count, ['n' => $s->boutiques_sans_niveau_count])).'</span>' : '')))
                    ->texte(fn (Site $s) => $s->boutiques_count),
                Colonne::make('version', $c['version'])->numerique()->triable()->valeur(fn (Site $s) => $s->version ?: '—'),
                // Donnée financière : jamais calculée ni envoyée au GESTIONNAIRE DES SITES ET BOUTIQUES.
                Colonne::make('cout_total', $c['cout_total'])->numerique()->droit(Droit::BoutiquesVoir)->avec(fn ($q) => $q->with('finances'))
                    ->valeur(fn (Site $s) => $s->finances?->cout_total !== null ? format_nombre($s->finances->cout_total) : '—')
                    ->texte(fn (Site $s) => $s->finances?->cout_total),
                Colonne::make('finances', $c['finances'])->droit(Droit::BoutiquesSaisir)->avec(fn ($q) => $q->with('finances'))
                    ->valeur(fn (Site $s) => $s->finances ? BadgeStatut::html($s->finances->statut) : '')->exportable(false),
                Colonne::make('actions', $c['actions'])->vue('admin.sites._actions', 'site')->exportable(false),
            ])
            ->filtres([
                Filtre::make('statut', $c['statut'], collect(StatutSite::cases())->mapWithKeys(fn ($x) => [$x->value => $x->label()])->all(), 'statut'),
                Filtre::make('phase', $c['phase'], collect(PhaseSite::cases())->mapWithKeys(fn ($x) => [$x->value => $x->label()])->all(), 'phase'),
                Filtre::make('ville', ucfirst(__('admin.sites.champs.ville')), Site::query()->whereNotNull('ville')->distinct()->orderBy('ville')->pluck('ville', 'ville')->all(), 'ville'),
            ])
            // Soumission groupée : sites pour le GESTIONNAIRE, données financières des sites pour le RÉGULATEUR.
            ->selection($request->user()->can(Droit::SitesGerer->value) ? 'site' : 'finances', $request->user())
            ->triParDefaut('nom')
            ->export('Raaga_liste-sites')
            ->repondre($request, fn (ResultatTableau $t) => view('admin.sites.index', ['tableau' => $t]));
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

        return $this->puisSoumettre($request, redirect()->route('admin.sites.show', $site)->with('succes', __('admin.sites.messages.enregistre')),
            'site', $site, route('admin.sites.edit', $site));
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
            'versions' => $versions->reverse()->values(),
            'decisions' => ParametrageDecision::query()->where('objet_type', 'site')->where('objet_id', $site->id)->with('decideur')->orderByDesc('id')->get(),
            'differentiel' => $differentiel,
            'premiereVersion' => $precedente === null,
            'capacites' => $site->capacites()->with('type')->get(),
            'comparaison' => app(ComparaisonCapacites::class)->pour($site),
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

        return $this->puisSoumettre($request, $this->executer($site, function () use ($request, $site, $donnees) {
            $this->exigerBrouillon($site);
            $ancienCode = $site->code;
            // Un changement de code recalcule les codes des boutiques : tout ou rien.
            DB::transaction(fn () => $site->fill($donnees)->save());
            $this->journal->ecrire($request->user(), 'site.modifie', 'Site', $site->id,
                $ancienCode !== $site->code ? ['code' => $ancienCode] : null, $ancienCode !== $site->code ? ['code' => $site->code] : null);
        }, 'admin.sites.messages.enregistre'), 'site', $site, route('admin.sites.edit', $site));
    }

    // ------------------------------------------------------------------ Boutiques d'un site (onglet Boutiques du Référencement)

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
        }, 'admin.sites.boutiques.ajoutee', true);
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
        }, 'admin.sites.boutiques.generees', true);
    }

    /** Fiche d'une boutique : niveau, bloc et numéro ne se saisissent que tant que le code manque (boutiques saisies avant les codes). */
    public function editBoutique(Request $request, Boutique $boutique): View|RedirectResponse
    {
        Gate::authorize(Droit::SitesGerer->value);
        $site = $boutique->site;

        if (! $site->estModifiable()) {
            return redirect()->route('admin.referencement.boutiques.show', $boutique)->with('erreur', __('admin.parametrage.regles.non_modifiable'));
        }
        if ($boutique->statut->verrouillee()) {
            return redirect()->route('admin.referencement.boutiques.show', $boutique)->with('erreur', __('admin.sites.boutiques.verrouillee'));
        }

        // Fenêtre modale chargée depuis la liste ; sans JavaScript, fiche de la boutique avec la fenêtre ouverte.
        return $request->hasHeader('X-Fenetre')
            ? view('admin.referencement._fenetre-boutique', ['boutique' => $boutique, 'types' => self::typesPour($boutique), 'ouvrir' => true])
            : redirect()->route('admin.referencement.boutiques.show', [$boutique, 'modifier' => 1]);
    }

    /** Types utilisables, plus le type actuel de la boutique s'il a été désactivé. */
    public static function typesPour(?Boutique $boutique = null): Collection
    {
        return TypeBoutique::query()->where(fn ($q) => $q->utilisables()->orWhere('id', $boutique?->type_boutique_id ?? 0))->orderBy('ordre')->orderBy('libelle')->get();
    }

    /** Ajout depuis la liste des boutiques (fenêtre) : site choisi, une boutique ou une série numérotée. */
    public function ajouterDepuisListe(Request $request): RedirectResponse
    {
        Gate::authorize(Droit::SitesGerer->value);
        $donnees = $request->validate([
            'site_id' => ['required', 'integer', fn (string $a, mixed $v, Closure $echec) => ($s = Site::query()->find($v)) && $s->estModifiable() && $s->code !== null
                ? null : $echec(__('admin.referencement.boutiques.site_fige'))],
            'mode' => ['required', 'in:une,serie'],
        ], [], ['site_id' => __('admin.imports.site'), 'mode' => mb_strtolower(__('admin.referencement.boutiques.mode'))]);
        $site = Site::query()->findOrFail($donnees['site_id']);

        return $donnees['mode'] === 'serie' ? $this->genererBoutiques($request, $site) : $this->ajouterBoutique($request, $site);
    }

    public function updateBoutique(Request $request, Boutique $boutique): RedirectResponse
    {
        Gate::authorize(Droit::SitesGerer->value);
        $site = $boutique->site;
        $this->majuscules($request, ['bloc']);
        $donnees = $request->validate([
            ...($boutique->code === null ? [...$this->reglesCode(), 'numero' => $this->regleNumero()] : []),
            ...$this->reglesFiche($boutique),
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
        }, 'admin.sites.boutiques.modifiee', true);
    }

    public function supprimerBoutique(Request $request, Boutique $boutique): RedirectResponse
    {
        Gate::authorize(Droit::SitesGerer->value);
        $site = $boutique->site;

        return $this->executer($site, function () use ($request, $site, $boutique) {
            $this->exigerBrouillon($site);
            if ($boutique->statut->verrouillee()) {
                throw new RegleGestionException(__('admin.sites.boutiques.verrouillee'));
            }
            // Suppression logique : la boutique garde son code, qui ne sera jamais réutilisé.
            $this->journal->ecrire($request->user(), 'boutique.supprimee', 'Boutique', $boutique->id, ['site' => $site->id, 'code' => $boutique->reference()]);
            $boutique->delete();
        }, 'admin.sites.boutiques.supprimee', true);
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
    /** Site validé : seul le VALIDATEUR en autorise la modification (motif obligatoire) ; le site repasse en brouillon. */
    public function reviser(Request $request, Site $site): RedirectResponse
    {
        Gate::authorize(Droit::SitesConfirmer->value);
        $motif = $request->validate(['motif' => ['required', 'string', 'min:5', 'max:1000']], ['motif.*' => __('admin.utilisateurs.erreurs.motif')])['motif'];

        return $this->executer($site, function () use ($request, $site, $motif) {
            if ($site->statut !== StatutSite::Confirme) {
                throw new RegleGestionException(__('admin.sites.regles.revision_confirme'));
            }
            // Une demande d'autorisation était en attente : son auteur est prévenu de l'accord.
            if ($demandeur = $site->revision_demandee_par) {
                DB::afterCommit(fn () => $this->notifierDemande($demandeur, $site, 'confirmer', null));
            }
            $site->forceFill(['statut' => StatutSite::Brouillon, 'revision_demandee_par' => null, 'revision_demandee_le' => null, 'revision_demande_motif' => null])->save();
            $this->journal->ecrire($request->user(), 'site.modification_autorisee', 'Site', $site->id, ['statut' => 'CONFIRME'], ['statut' => 'BROUILLON', 'motif' => $motif]);
        }, 'admin.sites.messages.revision');
    }

    /** Site validé : le GESTIONNAIRE demande au VALIDATEUR l'autorisation de le modifier (motif obligatoire). */
    public function demanderRevision(Request $request, Site $site): RedirectResponse
    {
        Gate::authorize(Droit::SitesGerer->value);
        $motif = $request->validate(['motif' => ['required', 'string', 'min:5', 'max:1000']], ['motif.*' => __('admin.utilisateurs.erreurs.motif')])['motif'];

        return $this->executer($site, function () use ($request, $site, $motif) {
            if ($site->statut !== StatutSite::Confirme) {
                throw new RegleGestionException(__('admin.sites.regles.revision_confirme'));
            }
            $site->forceFill(['revision_demandee_par' => $request->user()->id, 'revision_demandee_le' => now(), 'revision_demande_motif' => $motif])->save();
            $this->journal->ecrire($request->user(), 'site.modification_demandee', 'Site', $site->id, null, ['motif' => $motif]);
        }, 'admin.sites.messages.revision_demandee');
    }

    /** Demande d'autorisation refusée par le VALIDATEUR (motif obligatoire) : le site reste validé. */
    public function refuserRevision(Request $request, Site $site): RedirectResponse
    {
        Gate::authorize(Droit::SitesConfirmer->value);
        $motif = $request->validate(['motif' => ['required', 'string', 'min:5', 'max:1000']], ['motif.*' => __('admin.utilisateurs.erreurs.motif')])['motif'];

        return $this->executer($site, function () use ($request, $site, $motif) {
            if ($site->revision_demandee_le === null) {
                throw new RegleGestionException(__('admin.sites.regles.aucune_demande'));
            }
            $demandeur = $site->revision_demandee_par;
            $site->forceFill(['revision_demandee_par' => null, 'revision_demandee_le' => null, 'revision_demande_motif' => null])->save();
            $this->journal->ecrire($request->user(), 'site.modification_refusee', 'Site', $site->id, null, ['motif' => $motif]);
            DB::afterCommit(fn () => $this->notifierDemande($demandeur, $site, 'rejeter', $motif));
        }, 'admin.sites.messages.revision_refusee');
    }

    /** @return array<string, mixed> */
    /** Demande d'autorisation de modifier un site validé : décision notifiée au GESTIONNAIRE qui l'a déposée. */
    private function notifierDemande(?int $demandeur, Site $site, string $decision, ?string $motif): void
    {
        $this->notifications->envoyer($demandeur, __('admin.a_valider.elements.autorisation'), ($site->code ? $site->code.' · ' : '').$site->nom,
            $decision, $motif, route('admin.sites.show', $site));
    }

    private function valider(Request $request, ?Site $site = null): array
    {
        $this->majuscules($request, ['code']);

        return FicheSite::attributs($request->validate(FicheSite::regles($site), [], __('admin.sites.champs')));
    }

    /** Éléments du code boutique saisis par le gestionnaire, hors numéro. @return array<string, list<mixed>> */
    private function reglesCode(): array
    {
        return [
            'niveau' => ['required', 'string', function (string $attribut, mixed $valeur, Closure $echec) {
                // Niveau inconnu, désactivé ou pas encore validé : refusé.
                $niveau = Niveau::query()->where('code', $valeur)->first();
                if ($niveau === null) {
                    $echec(__('admin.zones.regles.niveau_inconnu', ['code' => $valeur]));
                } elseif (! $niveau->estUtilisable()) {
                    $echec($niveau->motifInutilisable());
                }
            }],
            'bloc' => ['required', 'string', 'regex:'.Boutique::FORMAT_BLOC],
        ];
    }

    /** @return list<mixed> */
    private function regleNumero(): array
    {
        return ['required', 'integer', 'min:1', 'max:'.str_repeat('9', Boutique::CHIFFRES_NUMERO)];
    }

    /** @return array<string, list<mixed>> */
    private function reglesFiche(?Boutique $boutique = null): array
    {
        return [
            'surface' => ['nullable', 'numeric', 'min:0.5', 'max:99999'],
            'type_boutique_id' => ['required', 'integer', function (string $attribut, mixed $valeur, Closure $echec) use ($boutique) {
                // Type désactivé ou non validé : refusé, sauf pour une boutique qui le porte déjà.
                $type = TypeBoutique::query()->find($valeur);
                if ($type === null) {
                    $echec(__('validation.exists', ['attribute' => __('admin.sites.boutiques.attributs.type_boutique_id')]));
                } elseif (! $type->estUtilisable() && $type->id !== $boutique?->type_boutique_id) {
                    $echec($type->motifInutilisable());
                }
            }],
            'position' => ['nullable', Rule::enum(PositionBoutique::class)],
            'coffret' => ['nullable', 'string', 'max:60'],
            'planche' => ['nullable', 'string', 'max:60'],
            'etat_physique' => ['required', Rule::enum(EtatPhysiqueBoutique::class)],
            'observations' => ['nullable', 'string', 'max:500'],
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

    /** Retour à la fiche du site, ou à l'onglet Boutiques filtré sur le site pour les actions sur ses boutiques. */
    private function executer(Site $site, Closure $action, string $message, bool $boutiques = false): RedirectResponse
    {
        $retour = $boutiques ? redirect()->route('admin.referencement.boutiques', ['site' => $site->id]) : redirect()->route('admin.sites.show', $site);
        try {
            $action();
        } catch (RegleGestionException $e) {
            return $retour->with('erreur', $e->getMessage());
        }

        return $retour->with('succes', __($message));
    }
}
