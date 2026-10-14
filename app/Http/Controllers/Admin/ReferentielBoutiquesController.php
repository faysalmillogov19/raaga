<?php

namespace App\Http\Controllers\Admin;

use App\Enums\Droit;
use App\Enums\EtatPhysiqueBoutique;
use App\Enums\StatutBoutique;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Boutique;
use App\Models\HistoriqueCode;
use App\Models\Niveau;
use App\Models\Site;
use App\Models\TypeBoutique;
use App\Services\Boutiques\ReferentielExcel;
use App\Support\BadgeStatut;
use App\Support\Tableau\Colonne;
use App\Support\Tableau\Filtre;
use App\Support\Tableau\ResultatTableau;
use App\Support\Tableau\Tableau;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Toutes les boutiques, tous sites confondus : recherche par code, filtres, compteurs, export Excel, fiche avec
 * historique. Ajout et import Excel pour un site choisi dans la liste (en brouillon ou en révision) ; les actions
 * elles-mêmes restent celles de SitesController et d'ImportBoutiquesController.
 */
class ReferentielBoutiquesController extends Controller
{
    /** Boutiques par page. */
    private const PAR_PAGE = 50;

    public function index(Request $request): Response|StreamedResponse|View
    {
        Gate::authorize(Droit::SitesVoir->value);
        $b = __('admin.referencement.boutiques');
        $c = $b['colonnes'];
        $sites = Site::query()->orderBy('code')->get(['id', 'code', 'nom']);

        return Tableau::pour(Boutique::query()->with(['site', 'type']), 'boutiques')
            ->colonnes([
                Colonne::make('code', $c['code'])->triable(fn ($q, $sens) => $q->orderByRaw('code IS NULL')->orderBy('code', $sens))->recherchable(['code', 'numero'])
                    ->classe('whitespace-nowrap font-bold')->valeur(fn (Boutique $x) => $x->reference()),
                Colonne::make('site', $c['site'])->triable('site_id')->recherchable(fn ($q, $terme) => $q->whereHas('site', fn ($s) => $s->where('code', 'like', '%'.$terme.'%')->orWhere('nom', 'like', '%'.$terme.'%')))
                    ->valeur(fn (Boutique $x) => $x->site->code ?? $x->site->nom),
                Colonne::make('niveau', $c['niveau'])->triable()->valeur(fn (Boutique $x) => $x->niveau ?? '—'),
                Colonne::make('zone', $c['zone'])->triable('bloc')->recherchable('bloc')->valeur(fn (Boutique $x) => $x->bloc ?: '—'),
                Colonne::make('type', $c['type'])->triable('type_boutique_id')->valeur(fn (Boutique $x) => $x->type->code ?? $x->type->libelle),
                Colonne::make('surface', $c['surface'])->numerique()->triable()
                    ->valeur(fn (Boutique $x) => $x->surface !== null ? rtrim(rtrim(format_nombre((float) $x->surface, 2), '0'), ',') : '—')->texte(fn (Boutique $x) => $x->surface),
                Colonne::make('etat', $c['etat'])->triable('etat_physique')
                    ->valeur(fn (Boutique $x) => BadgeStatut::html($x->etat_physique?->label() ?? $b['non_renseigne']))->texte(fn (Boutique $x) => $x->etat_physique?->label()),
                Colonne::make('statut', $c['statut'])->triable()->valeur(fn (Boutique $x) => BadgeStatut::html($x->statut))->texte(fn (Boutique $x) => $x->statut->label()),
                Colonne::make('actions', $c['actions'])->vue('admin.referencement._boutique-actions', 'boutique')->exportable(false),
            ])
            ->filtres([
                Filtre::make('site', $b['site'], $sites->mapWithKeys(fn ($s) => [$s->id => ($s->code ? $s->code.' · ' : '').$s->nom])->all(), 'site_id'),
                Filtre::make('niveau', $b['niveau'], Niveau::query()->orderBy('rang')->get()->mapWithKeys(fn ($n) => [$n->code => $n->code.' · '.$n->libelle])->all(), 'niveau'),
                Filtre::make('type', $b['type'], TypeBoutique::query()->orderBy('ordre')->orderBy('libelle')->get()->mapWithKeys(fn ($t) => [$t->id => ($t->code ? $t->code.' · ' : '').$t->libelle])->all(), 'type_boutique_id'),
                Filtre::make('statut', $b['statut'], collect(StatutBoutique::cases())->mapWithKeys(fn ($s) => [$s->value => $s->label()])->all(), 'statut'),
                // Liens venus d'autres écrans (capacités prévues) : zone et état physique, sans liste affichée.
                Filtre::make('zone', $b['zone'], Boutique::query()->whereNotNull('bloc')->distinct()->pluck('bloc', 'bloc')->all(), 'bloc')->masque(),
                Filtre::make('etat', $b['etat'], collect(EtatPhysiqueBoutique::cases())->mapWithKeys(fn ($e) => [$e->value => $e->label()])->all(), 'etat_physique')->masque(),
            ])
            ->triParDefaut('code')
            ->lignesParDefaut(10)
            ->unite($b['unite'])
            ->exportEnTete()
            ->export('Raaga_boutiques')
            ->repondre($request, fn (ResultatTableau $t) => view('admin.referencement.boutiques', [
                'tableau' => $t,
                'sites' => $sites,
                'sitesModifiables' => $this->sitesModifiables(),
                'imports' => ($id = $t->etat['filtres']['site'] ?? null) && ($site = Site::query()->find($id)) ? ImportBoutiquesController::journal($site) : collect(),
            ]));
    }

    /** Ajout de boutiques : on choisit d'abord le site (brouillon ou révision, avec un code), puis une boutique ou une série. */
    public function ajouter(Request $request): View
    {
        Gate::authorize(Droit::SitesGerer->value);
        $sites = $this->sitesModifiables();
        $choisi = $request->integer('site') ?: null;

        return view('admin.referencement.ajouter', [
            'sites' => $sites,
            'site' => $choisi ? $sites->firstWhere('id', $choisi) : null,
            'types' => TypeBoutique::query()->utilisables()->orderBy('ordre')->orderBy('libelle')->get(),
        ]);
    }

    /** Export Excel des boutiques filtrées, au format du modèle « Locaux ». */
    public function exporter(Request $request, ReferentielExcel $excel): StreamedResponse
    {
        Gate::authorize(Droit::SitesVoir->value);

        return $excel->telechargerBoutiques($this->requete($this->filtres($request))->with(['site', 'type'])->orderBy('site_id')->orderBy('code')->get());
    }

    public function show(Boutique $boutique): View
    {
        Gate::authorize(Droit::SitesVoir->value);

        return view('admin.referencement.boutique', [
            'boutique' => $boutique->load(['site', 'type']),
            'journal' => AuditLog::query()->where('entite_type', 'Boutique')->where('entite_id', (string) $boutique->id)->with('acteur')->latest('id')->get(),
            'anciensCodes' => HistoriqueCode::query()->boutiques()->where('objet_id', $boutique->id)->orderBy('id')->get(),
        ]);
    }

    /** Sites où l'on peut ajouter, importer ou modifier des boutiques : brouillon ou révision, avec un code. */
    private function sitesModifiables(): Collection
    {
        return Site::query()->whereNotNull('code')->orderBy('code')->get()->filter->estModifiable()->values();
    }

    /** @return array<string, mixed> */
    private function filtres(Request $request): array
    {
        return $request->validate([
            'q' => ['nullable', 'string', 'max:30'],
            'site' => ['nullable', 'integer'],
            'niveau' => ['nullable', 'string', 'max:3'],
            'zone' => ['nullable', 'string', 'max:5'],
            'type' => ['nullable', 'integer'],
            'etat' => ['nullable', Rule::enum(EtatPhysiqueBoutique::class)],
            'statut' => ['nullable', Rule::enum(StatutBoutique::class)],
        ]);
    }

    /** La zone d'une boutique est son bloc (A, B, MAG…). @return Builder<Boutique> */
    private function requete(array $f): Builder
    {
        return Boutique::query()
            ->when($f['q'] ?? null, fn ($q, $texte) => $q->where(fn ($q) => $q->where('code', 'like', '%'.mb_strtoupper($texte).'%')->orWhere('numero', 'like', '%'.$texte.'%')))
            ->when($f['site'] ?? null, fn ($q, $site) => $q->where('site_id', $site))
            ->when($f['niveau'] ?? null, fn ($q, $niveau) => $q->where('niveau', $niveau))
            ->when($f['zone'] ?? null, fn ($q, $zone) => $q->where('bloc', mb_strtoupper($zone)))
            ->when($f['type'] ?? null, fn ($q, $type) => $q->where('type_boutique_id', $type))
            ->when($f['etat'] ?? null, fn ($q, $etat) => $q->where('etat_physique', $etat))
            ->when($f['statut'] ?? null, fn ($q, $statut) => $q->where('statut', $statut));
    }
}
