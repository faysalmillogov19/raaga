<?php

namespace App\Http\Controllers\Admin;

use App\Enums\Droit;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Services\Backoffice\RegleGestionException;
use App\Services\Sites\ReferentielSitesExcel;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Sites et capacités prévues en Excel (GESTIONNAIRE DES SITES ET BOUTIQUES) : modèle, export, import en deux temps
 * — aperçu sans écriture, puis confirmation tout ou rien, seulement si le fichier ne contient aucune erreur.
 * Les sites importés sont créés en brouillon ; le VALIDATEUR les confirme ensuite, un par un.
 */
class ImportSitesController extends Controller
{
    private const CLE_SESSION = 'import_sites';

    public function __construct(private readonly ReferentielSitesExcel $excel) {}

    public function modele(): StreamedResponse
    {
        Gate::authorize(Droit::SitesGerer->value);

        return $this->excel->telecharger(false);
    }

    public function exporter(): StreamedResponse
    {
        Gate::authorize(Droit::SitesGerer->value);

        return $this->excel->telecharger(true);
    }

    /** Dépôt du fichier : conservé hors du dossier public, puis aperçu. */
    public function deposer(Request $request): RedirectResponse
    {
        Gate::authorize(Droit::SitesGerer->value);
        $config = config('raaga.referentiel_sites');
        $fichier = $request->validate([
            'fichier_sites' => ['required', 'file', 'extensions:xlsx', 'mimes:xlsx', 'max:'.$config['taille_max_ko']],
        ], [], ['fichier_sites' => __('admin.sites.import.fichier')])['fichier_sites'];

        $chemin = $fichier->storeAs($config['dossier'], now()->format('Ymd-His').'-'.Str::uuid().'.xlsx', $config['disque']);
        $request->session()->put(self::CLE_SESSION, [
            'chemin' => $chemin,
            'nom' => Str::limit($fichier->getClientOriginalName(), 150, ''),
            'empreinte' => hash_file('sha256', Storage::disk($config['disque'])->path($chemin)),
        ]);

        return redirect()->route('admin.sites.excel.apercu');
    }

    /** Aperçu : sites à créer, à mettre à jour, capacités, erreurs ; rien n'est enregistré. */
    public function apercu(Request $request): View|RedirectResponse
    {
        Gate::authorize(Droit::SitesGerer->value);
        $depot = $request->session()->get(self::CLE_SESSION);
        if ($depot === null) {
            return redirect()->route('admin.sites');
        }

        return view('admin.sites.import-sites', [
            'depot' => $depot,
            'analyse' => $this->excel->analyser($this->chemin($depot)),
        ]);
    }

    public function confirmer(Request $request): RedirectResponse
    {
        Gate::authorize(Droit::SitesGerer->value);
        $depot = $request->session()->get(self::CLE_SESSION);
        if ($depot === null) {
            return redirect()->route('admin.sites');
        }

        // Nouvelle analyse : un site a pu être soumis ou confirmé depuis l'aperçu.
        try {
            $compte = $this->excel->importer($this->excel->analyser($this->chemin($depot)), $request->user(), $depot['nom'], $depot['empreinte']);
        } catch (RegleGestionException $e) {
            return redirect()->route('admin.sites.excel.apercu')->with('erreur', $e->getMessage());
        }
        $request->session()->forget(self::CLE_SESSION);

        return redirect()->route('admin.sites')->with('succes', __('admin.sites.import.importe', array_map('format_nombre', $compte)));
    }

    public function annuler(Request $request): RedirectResponse
    {
        Gate::authorize(Droit::SitesGerer->value);
        $request->session()->forget(self::CLE_SESSION);

        return redirect()->route('admin.sites');
    }

    /** Derniers imports de sites (le plus récent d'abord). @return Collection<int, AuditLog> */
    public static function journal(): Collection
    {
        return AuditLog::query()->where('action', 'sites.importes')->with('acteur')->latest('id')->limit(10)->get();
    }

    private function chemin(array $depot): string
    {
        return Storage::disk(config('raaga.referentiel_sites.disque'))->path($depot['chemin']);
    }
}
