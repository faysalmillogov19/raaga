<?php

namespace App\Http\Controllers\Admin;

use App\Enums\Droit;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Site;
use App\Support\Tableau\Colonne;
use App\Support\Tableau\Filtre;
use App\Support\Tableau\ResultatTableau;
use App\Support\Tableau\Tableau;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\HtmlString;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Imports du référencement : boutons d'import (sites, boutiques d'un site, boutiques de plusieurs sites) pour le
 * GESTIONNAIRE DES SITES ET BOUTIQUES, et journal de tous les imports avec le fichier d'origine téléchargeable.
 */
class ImportsController extends Controller
{
    /** Actions du journal d'audit qui sont des imports : [action => [type, configuration du stockage]]. */
    public const ACTIONS = ['sites.importes' => 'referentiel_sites', 'boutiques.importees' => 'referentiel_boutiques'];

    public function index(Request $request): Response|StreamedResponse|View
    {
        Gate::authorize(Droit::ImportsVoir->value);
        $i = __('admin.imports');
        $c = $i['colonnes'];
        $codes = null; // Codes des sites, lus une seule fois.
        $site = function (AuditLog $e) use (&$codes, $i) {
            $codes ??= Site::query()->pluck('code', 'id');

            return $e->action === 'boutiques.importees' ? ($codes[$e->entite_id] ?? '—') : $i['tous_sites'];
        };
        $resultat = fn (AuditLog $e) => $e->action === 'sites.importes'
            ? __('admin.imports.resultat_sites', ['crees' => $e->nouvelle_valeur['sites_crees'] ?? 0, 'maj' => $e->nouvelle_valeur['sites_mis_a_jour'] ?? 0,
                'inchanges' => $e->nouvelle_valeur['sites_inchanges'] ?? 0, 'capacites' => $e->nouvelle_valeur['capacites'] ?? 0])
            : __('admin.imports.resultat_boutiques', ['crees' => $e->nouvelle_valeur['creees'] ?? 0, 'maj' => $e->nouvelle_valeur['mises_a_jour'] ?? 0,
                'inchanges' => $e->nouvelle_valeur['inchangees'] ?? 0]);

        return Tableau::pour(AuditLog::query()->whereIn('action', array_keys(self::ACTIONS))->with('acteur'), 'imports')
            ->colonnes([
                Colonne::make('date', $c['date'])->triable('created_at')->classe('whitespace-nowrap')->valeur(fn (AuditLog $e) => $e->created_at->format('d/m/Y H:i')),
                Colonne::make('auteur', $c['auteur'])->recherchable(fn ($q, $terme) => $q->whereHas('acteur', fn ($a) => $a->where('name', 'like', '%'.$terme.'%')))
                    ->valeur(fn (AuditLog $e) => $e->acteur?->name ?? '—'),
                Colonne::make('type', $c['type'])->triable('action')->valeur(fn (AuditLog $e) => $i['types'][$e->action] ?? $e->action),
                Colonne::make('site', $c['site'])->valeur($site),
                Colonne::make('fichier', $c['fichier'])->recherchable('nouvelle_valeur')
                    ->valeur(fn (AuditLog $e) => new HtmlString(e($e->nouvelle_valeur['fichier'] ?? '—').'<br>'.(! empty($e->nouvelle_valeur['chemin'])
                        ? '<a href="'.e(route('admin.referencement.imports.fichier', $e)).'" class="font-semibold text-vert-fonce underline underline-offset-2">'.e($i['telecharger']).'</a>'
                        : '<span class="texte-carte">'.e($i['indisponible']).'</span>')))
                    ->texte(fn (AuditLog $e) => $e->nouvelle_valeur['fichier'] ?? null),
                Colonne::make('resultat', $c['resultat'])->classe('text-sm')->valeur($resultat),
            ])
            ->filtres([
                Filtre::make('type', $c['type'], $i['types'], 'action'),
            ])
            ->triParDefaut('date', 'desc')
            ->export('Raaga_journal-imports')
            ->repondre($request, fn (ResultatTableau $t) => view('admin.referencement.imports', [
                'tableau' => $t,
                'sitesModifiables' => Site::query()->whereNotNull('code')->orderBy('code')->get()->filter->estModifiable(),
            ]));
    }

    /** Fichier d'origine d'un import, conservé hors du dossier public. */
    public function fichier(AuditLog $journal): StreamedResponse
    {
        Gate::authorize(Droit::ImportsVoir->value);
        $config = self::ACTIONS[$journal->action] ?? abort(404);
        $chemin = $journal->nouvelle_valeur['chemin'] ?? null;
        $disque = Storage::disk(config('raaga.'.$config.'.disque'));
        abort_unless($chemin && $disque->exists($chemin), 404);

        return $disque->download($chemin, $journal->nouvelle_valeur['fichier'] ?? basename($chemin));
    }
}
