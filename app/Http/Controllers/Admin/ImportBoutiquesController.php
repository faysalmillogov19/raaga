<?php

namespace App\Http\Controllers\Admin;

use App\Enums\Droit;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Site;
use App\Services\Backoffice\RegleGestionException;
use App\Services\Boutiques\ReferentielExcel;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Boutiques en Excel (GESTIONNAIRE DES SITES ET BOUTIQUES) : modèle, export et import en deux temps — aperçu sans
 * écriture, puis confirmation tout ou rien, seulement si le fichier ne contient aucune erreur (les avertissements
 * ne bloquent pas). Import pour un site choisi dans la liste (onglet Boutiques ou Imports), ou combiné (plusieurs sites
 * existants) : le site choisi tient lieu de site de référence (colonne Site vide → son code ; autre site → erreur).
 */
class ImportBoutiquesController extends Controller
{
    public function __construct(private readonly ReferentielExcel $excel) {}

    // ------------------------------------------------------------------ Pour un site

    public function modele(Site $site): StreamedResponse|RedirectResponse
    {
        Gate::authorize(Droit::SitesGerer->value);

        return $this->exigerModifiable($site) ?? $this->excel->telecharger($site, false);
    }

    public function exporter(Site $site): StreamedResponse
    {
        Gate::authorize(Droit::SitesGerer->value);

        return $this->excel->telecharger($site, true);
    }

    public function deposer(Request $request, Site $site): RedirectResponse
    {
        Gate::authorize(Droit::SitesGerer->value);

        return $this->exigerModifiable($site) ?? $this->depot($request, $site);
    }

    /** Onglet Boutiques ou Imports : le site est choisi dans la liste (brouillon ou révision), puis l'import suit son circuit. */
    public function deposerPourSite(Request $request): RedirectResponse
    {
        Gate::authorize(Droit::SitesGerer->value);

        return $this->deposer($request, $this->siteChoisi($request));
    }

    /** Modèle pré-rempli du site choisi (brouillon ou révision). */
    public function modelePourSite(Request $request): StreamedResponse|RedirectResponse
    {
        return $this->modele($this->siteChoisi($request));
    }

    /** Export du référentiel du site choisi, quel que soit son statut. */
    public function exporterPourSite(Request $request): StreamedResponse
    {
        return $this->exporter($this->siteChoisi($request));
    }

    public function apercu(Request $request, Site $site): View|RedirectResponse
    {
        Gate::authorize(Droit::SitesGerer->value);

        return $this->vueApercu($request, $site);
    }

    public function confirmer(Request $request, Site $site): RedirectResponse
    {
        Gate::authorize(Droit::SitesGerer->value);

        return $this->confirmation($request, $site);
    }

    public function annuler(Request $request, Site $site): RedirectResponse
    {
        Gate::authorize(Droit::SitesGerer->value);
        $request->session()->forget($this->cle($site));

        return $this->retour($site);
    }

    // ------------------------------------------------------------------ Import combiné (plusieurs sites)

    public function modeleCombine(): StreamedResponse
    {
        Gate::authorize(Droit::SitesGerer->value);

        return $this->excel->telecharger(null, false);
    }

    public function deposerCombine(Request $request): RedirectResponse
    {
        Gate::authorize(Droit::SitesGerer->value);

        return $this->depot($request, null);
    }

    public function apercuCombine(Request $request): View|RedirectResponse
    {
        Gate::authorize(Droit::SitesGerer->value);

        return $this->vueApercu($request, null);
    }

    public function confirmerCombine(Request $request): RedirectResponse
    {
        Gate::authorize(Droit::SitesGerer->value);

        return $this->confirmation($request, null);
    }

    public function annulerCombine(Request $request): RedirectResponse
    {
        Gate::authorize(Droit::SitesGerer->value);
        $request->session()->forget($this->cle(null));

        return redirect()->route('admin.sites');
    }

    /** Journal des imports de boutiques d'un site (le plus récent d'abord). */
    public static function journal(Site $site): Collection
    {
        return AuditLog::query()->where('action', 'boutiques.importees')->where('entite_type', 'Site')->where('entite_id', (string) $site->id)
            ->with('acteur')->latest('id')->limit(10)->get();
    }

    // ------------------------------------------------------------------ Commun

    /** Dépôt du fichier : conservé hors du dossier public, puis aperçu. */
    private function depot(Request $request, ?Site $site): RedirectResponse
    {
        $config = config('raaga.referentiel_boutiques');
        $fichier = $request->validate([
            'fichier' => ['required', 'file', 'extensions:xlsx', 'mimes:xlsx', 'max:'.$config['taille_max_ko']],
        ], [], ['fichier' => __('admin.sites.excel.fichier')])['fichier'];

        $chemin = $fichier->storeAs($config['dossier'].'/'.($site?->id ?? 'combine'), now()->format('Ymd-His').'-'.Str::uuid().'.xlsx', $config['disque']);
        $request->session()->put($this->cle($site), [
            'chemin' => $chemin,
            'nom' => Str::limit($fichier->getClientOriginalName(), 150, ''),
            'empreinte' => hash_file('sha256', Storage::disk($config['disque'])->path($chemin)),
        ]);

        return $site ? redirect()->route('admin.referencement.boutiques.excel.import', $site) : redirect()->route('admin.sites.boutiques-excel.apercu');
    }

    /** Aperçu : lignes valides, avertissements, erreurs et totaux ; rien n'est enregistré. */
    private function vueApercu(Request $request, ?Site $site): View|RedirectResponse
    {
        $depot = $request->session()->get($this->cle($site));
        if ($depot === null) {
            return $this->retour($site);
        }

        return view('admin.sites.import', [
            'site' => $site,
            'depot' => $depot,
            'analyse' => $this->excel->analyser($site, $this->chemin($depot)),
        ]);
    }

    private function confirmation(Request $request, ?Site $site): RedirectResponse
    {
        $depot = $request->session()->get($this->cle($site));
        if ($depot === null) {
            return $this->retour($site);
        }

        // Le fichier est analysé de nouveau : l'état des sites et des boutiques a pu changer depuis l'aperçu.
        try {
            $compte = $this->excel->importer($this->excel->analyser($site, $this->chemin($depot)), $request->user(), $depot['nom'], $depot['empreinte'], $depot['chemin']);
        } catch (RegleGestionException $e) {
            return ($site ? redirect()->route('admin.referencement.boutiques.excel.import', $site) : redirect()->route('admin.sites.boutiques-excel.apercu'))->with('erreur', $e->getMessage());
        }
        $request->session()->forget($this->cle($site));

        return $this->retour($site)->with('succes', __('admin.sites.excel.importe', [
            'creees' => format_nombre($compte['creees']), 'mises_a_jour' => format_nombre($compte['mises_a_jour']),
        ]));
    }

    /** Site soumis ou confirmé : import entièrement refusé (à remettre en révision). */
    private function exigerModifiable(Site $site): ?RedirectResponse
    {
        return $site->estModifiable() ? null
            : $this->retour($site)->with('erreur', $this->excel->motifVerrouille($site));
    }

    private function retour(?Site $site): RedirectResponse
    {
        return $site ? redirect()->route('admin.referencement.boutiques', ['site' => $site->id]) : redirect()->route('admin.sites');
    }

    private function siteChoisi(Request $request): Site
    {
        Gate::authorize(Droit::SitesGerer->value);

        return Site::query()->findOrFail($request->validate(['site_id' => ['required', 'integer']], [], ['site_id' => __('admin.imports.site')])['site_id']);
    }

    private function chemin(array $depot): string
    {
        return Storage::disk(config('raaga.referentiel_boutiques.disque'))->path($depot['chemin']);
    }

    private function cle(?Site $site): string
    {
        return 'import_boutiques.'.($site?->id ?? 'combine');
    }
}
