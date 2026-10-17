<?php

namespace App\Http\Controllers\Admin;

use App\Enums\Droit;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\ImportEnAttente;
use App\Models\Site;
use App\Services\Backoffice\RegleGestionException;
use App\Services\Boutiques\ReferentielExcel;
use App\Services\Imports\ValidationImport;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Boutiques en Excel (GESTIONNAIRE DES SITES ET BOUTIQUES) : modèle, export et import en deux temps · aperçu sans
 * écriture, puis confirmation tout ou rien, seulement si le fichier ne contient aucune erreur (les avertissements
 * ne bloquent pas). Import pour un site choisi dans la liste (onglet Boutiques ou Imports), ou combiné (plusieurs sites
 * existants) : le site choisi tient lieu de site de référence (colonne Site vide → son code ; autre site → erreur).
 * Un aperçu non confirmé reste « En attente de confirmation » (ImportEnAttente) : repris depuis le journal Imports,
 * expiré au bout de 24 heures, annulé par un nouvel import du même site.
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
        $this->attente($request->session()->pull($this->cle($site)))?->terminer(ImportEnAttente::ANNULE);

        return $this->retour($site);
    }

    /** « Reprendre » (journal Imports) : rouvre l'aperçu d'un import encore en attente de confirmation. */
    public function reprendre(Request $request, ImportEnAttente $import): RedirectResponse
    {
        Gate::authorize(Droit::SitesGerer->value);
        ImportEnAttente::expirer();
        $import->refresh();
        if (! $import->estActif()) {
            return $this->versJournal($import);
        }
        $request->session()->put($this->cle($import->site), ['chemin' => $import->chemin, 'nom' => $import->nom, 'empreinte' => $import->empreinte, 'attente' => $import->id]);

        return $import->site ? redirect()->route('admin.referencement.boutiques.excel.import', $import->site) : redirect()->route('admin.sites.boutiques-excel.apercu');
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
        $this->attente($request->session()->pull($this->cle(null)))?->terminer(ImportEnAttente::ANNULE);

        return redirect()->route('admin.referencement.imports.entite', 'boutiques');
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
        $depot = [
            'chemin' => $chemin,
            'nom' => Str::limit($fichier->getClientOriginalName(), 150, ''),
            'empreinte' => hash_file('sha256', Storage::disk($config['disque'])->path($chemin)),
        ];
        // Un nouvel import du même site (ou de plusieurs sites) annule l'aperçu précédent encore en attente.
        ImportEnAttente::expirer();
        ImportEnAttente::query()->where('entite', 'boutiques')->where('statut', ImportEnAttente::EN_ATTENTE)
            ->when($site, fn ($q) => $q->where('site_id', $site->id), fn ($q) => $q->whereNull('site_id'))
            ->update(['statut' => ImportEnAttente::ANNULE, 'termine_le' => now()]);
        $attente = ImportEnAttente::create([...$depot, 'entite' => 'boutiques', 'site_id' => $site?->id, 'user_id' => $request->user()->id,
            'statut' => ImportEnAttente::EN_ATTENTE, 'expire_le' => now()->addHours(ImportEnAttente::DUREE_HEURES)]);
        $request->session()->put($this->cle($site), [...$depot, 'attente' => $attente->id]);

        return $site ? redirect()->route('admin.referencement.boutiques.excel.import', $site) : redirect()->route('admin.sites.boutiques-excel.apercu');
    }

    /** Aperçu : lignes valides, avertissements, erreurs et totaux ; rien n'est enregistré. */
    private function vueApercu(Request $request, ?Site $site): View|RedirectResponse
    {
        $depot = $request->session()->get($this->cle($site));
        if ($depot === null) {
            return $this->retour($site);
        }
        if ($refus = $this->exigerEnAttente($request, $site, $depot)) {
            return $refus;
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
        if ($refus = $this->exigerEnAttente($request, $site, $depot)) {
            return $refus;
        }

        // Le fichier est analysé de nouveau : l'état des sites et des boutiques a pu changer depuis l'aperçu.
        try {
            $analyse = $this->excel->analyser($site, $this->chemin($depot));
            // Statut choisi dans la fenêtre « Confirmer l'import » : brouillon, ou sites soumis au VALIDATEUR après l'import.
            $resultat = app(ValidationImport::class)->importer($request, 'boutiques', $analyse, $depot,
                fn (array $journal) => $this->excel->importer($analyse, $request->user(), $depot['nom'], $depot['empreinte'], $depot['chemin'], $journal));
            $compte = $resultat['compte'];
        } catch (RegleGestionException $e) {
            return ($site ? redirect()->route('admin.referencement.boutiques.excel.import', $site) : redirect()->route('admin.sites.boutiques-excel.apercu'))->with('erreur', $e->getMessage());
        }
        $request->session()->forget($this->cle($site));
        $this->attente($depot)?->terminer(ImportEnAttente::CONFIRME);

        $message = __('admin.sites.excel.importe', ['creees' => format_nombre($compte['creees']), 'mises_a_jour' => format_nombre($compte['mises_a_jour'])])
            .($resultat['message'] ? ' '.$resultat['message'] : '');

        return $this->retour($site)->with('succes', $message)->with('succes_titre', __('public.popups.titres_succes.importe'));
    }

    /** Site soumis ou confirmé : import entièrement refusé (à remettre en révision). */
    private function exigerModifiable(Site $site): ?RedirectResponse
    {
        return $site->estModifiable() ? null
            : $this->retour($site)->with('erreur', $this->excel->motifVerrouille($site));
    }

    private function retour(?Site $site): RedirectResponse
    {
        // Import de plusieurs sites : la liste des boutiques (toutes), où les boutiques importées apparaissent.
        return $site ? redirect()->route('admin.referencement.boutiques', ['site' => $site->id]) : redirect()->route('admin.referencement.boutiques');
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

    private function attente(?array $depot): ?ImportEnAttente
    {
        return isset($depot['attente']) ? ImportEnAttente::query()->find($depot['attente']) : null;
    }

    /** Aperçu expiré (24 h) ou remplacé par un import plus récent : refusé, retour au journal Imports. */
    private function exigerEnAttente(Request $request, ?Site $site, array $depot): ?RedirectResponse
    {
        ImportEnAttente::expirer();
        $attente = $this->attente($depot);
        if ($attente === null || $attente->estActif()) {
            return null;
        }
        $request->session()->forget($this->cle($site));

        return $this->versJournal($attente);
    }

    private function versJournal(ImportEnAttente $import): RedirectResponse
    {
        return redirect()->route('admin.referencement.imports.entite', 'boutiques')
            ->with('erreur', __('admin.imports.attente.refus.'.($import->statut === ImportEnAttente::EXPIRE ? 'expire' : 'termine')));
    }

    private function cle(?Site $site): string
    {
        return 'import_boutiques.'.($site?->id ?? 'combine');
    }
}
