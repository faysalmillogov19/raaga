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
 * Référentiel des boutiques d'un site en Excel (GESTIONNAIRE DES SITES ET BOUTIQUES) : modèle, export et import
 * en deux temps — aperçu sans écriture, puis confirmation tout ou rien, seulement si le fichier ne contient aucune erreur.
 */
class ImportBoutiquesController extends Controller
{
    public function __construct(private readonly ReferentielExcel $excel) {}

    public function modele(Site $site): StreamedResponse|RedirectResponse
    {
        Gate::authorize(Droit::SitesGerer->value);

        return $this->exigerBrouillon($site) ?? $this->excel->telecharger($site, false);
    }

    public function exporter(Site $site): StreamedResponse
    {
        Gate::authorize(Droit::SitesGerer->value);

        return $this->excel->telecharger($site, true);
    }

    /** Dépôt du fichier : conservé hors du dossier public, puis aperçu. */
    public function deposer(Request $request, Site $site): RedirectResponse
    {
        Gate::authorize(Droit::SitesGerer->value);
        if ($refus = $this->exigerBrouillon($site)) {
            return $refus;
        }
        $config = config('raaga.referentiel_boutiques');
        $fichier = $request->validate([
            'fichier' => ['required', 'file', 'extensions:xlsx', 'mimes:xlsx', 'max:'.$config['taille_max_ko']],
        ], [], ['fichier' => __('admin.sites.excel.fichier')])['fichier'];

        $chemin = $fichier->storeAs($config['dossier'].'/'.$site->id, now()->format('Ymd-His').'-'.Str::uuid().'.xlsx', $config['disque']);
        $request->session()->put($this->cle($site), [
            'chemin' => $chemin,
            'nom' => Str::limit($fichier->getClientOriginalName(), 150, ''),
            'empreinte' => hash_file('sha256', Storage::disk($config['disque'])->path($chemin)),
        ]);

        return redirect()->route('admin.sites.import', $site);
    }

    /** Aperçu : lignes valides, erreurs et totaux ; rien n'est enregistré. */
    public function apercu(Request $request, Site $site): View|RedirectResponse
    {
        Gate::authorize(Droit::SitesGerer->value);
        $depot = $request->session()->get($this->cle($site));
        if ($depot === null) {
            return redirect()->route('admin.sites.show', $site);
        }

        return view('admin.sites.import', [
            'site' => $site,
            'depot' => $depot,
            'analyse' => $this->excel->analyser($site, Storage::disk(config('raaga.referentiel_boutiques.disque'))->path($depot['chemin'])),
        ]);
    }

    public function confirmer(Request $request, Site $site): RedirectResponse
    {
        Gate::authorize(Droit::SitesGerer->value);
        $depot = $request->session()->get($this->cle($site));
        if ($depot === null) {
            return redirect()->route('admin.sites.show', $site);
        }

        // Le fichier est analysé de nouveau : l'état des boutiques a pu changer depuis l'aperçu.
        $analyse = $this->excel->analyser($site, Storage::disk(config('raaga.referentiel_boutiques.disque'))->path($depot['chemin']));
        try {
            $compte = $this->excel->importer($site, $analyse, $request->user(), $depot['nom'], $depot['empreinte']);
        } catch (RegleGestionException $e) {
            return redirect()->route('admin.sites.import', $site)->with('erreur', $e->getMessage());
        }
        $request->session()->forget($this->cle($site));

        return redirect()->route('admin.sites.show', $site)->with('succes', __('admin.sites.excel.importe', [
            'creees' => format_nombre($compte['creees']), 'mises_a_jour' => format_nombre($compte['mises_a_jour']),
        ]));
    }

    public function annuler(Request $request, Site $site): RedirectResponse
    {
        Gate::authorize(Droit::SitesGerer->value);
        $request->session()->forget($this->cle($site));

        return redirect()->route('admin.sites.show', $site);
    }

    /** Journal des imports d'un site (le plus récent d'abord). */
    public static function journal(Site $site): Collection
    {
        return AuditLog::query()->where('action', 'boutiques.importees')->where('entite_type', 'Site')->where('entite_id', (string) $site->id)
            ->with('acteur')->latest('id')->limit(10)->get();
    }

    private function exigerBrouillon(Site $site): ?RedirectResponse
    {
        return $site->estModifiable() ? null
            : redirect()->route('admin.sites.show', $site)->with('erreur', __('admin.parametrage.regles.non_modifiable'));
    }

    private function cle(Site $site): string
    {
        return 'import_boutiques.'.$site->id;
    }
}
