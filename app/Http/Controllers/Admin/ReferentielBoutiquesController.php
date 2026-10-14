<?php

namespace App\Http\Controllers\Admin;

use App\Enums\Droit;
use App\Enums\EtatPhysiqueBoutique;
use App\Enums\StatutBoutique;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Boutique;
use App\Models\HistoriqueCode;
use App\Models\Site;
use App\Models\TypeBoutique;
use App\Models\ZoneSite;
use App\Services\Boutiques\ReferentielExcel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
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

    public function index(Request $request): View
    {
        Gate::authorize(Droit::SitesVoir->value);
        $filtres = $this->filtres($request);
        $requete = $this->requete($filtres);

        return view('admin.referencement.boutiques', [
            'boutiques' => (clone $requete)->with(['site', 'type'])->orderBy('site_id')->orderByRaw('code IS NULL, code, LENGTH(numero), numero')
                ->paginate(self::PAR_PAGE)->withQueryString(),
            'compteurs' => [
                'total' => (clone $requete)->count(),
                'statuts' => (clone $requete)->selectRaw('statut, count(*) as n')->groupBy('statut')->pluck('n', 'statut'),
                'etats' => (clone $requete)->selectRaw('etat_physique, count(*) as n')->groupBy('etat_physique')->pluck('n', 'etat_physique'),
            ],
            'filtres' => $filtres,
            'sites' => Site::query()->orderBy('code')->get(['id', 'code', 'nom']),
            'types' => TypeBoutique::query()->orderBy('ordre')->orderBy('libelle')->get(['id', 'code', 'libelle']),
            'zones' => ZoneSite::query()->orderBy('id')->get(['code', 'libelle']),
            'sitesModifiables' => $this->sitesModifiables(),
            'imports' => ($filtres['site'] ?? null) && ($site = Site::query()->find($filtres['site'])) ? ImportBoutiquesController::journal($site) : collect(),
        ]);
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
