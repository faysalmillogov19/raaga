<?php

namespace App\Http\Controllers\Admin;

use App\Enums\Droit;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Site;
use App\Support\BadgeStatut;
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

        return self::journal()->repondre($request, fn (ResultatTableau $t) => view('admin.referencement.imports', [
            'tableau' => $t,
            'sitesModifiables' => Site::query()->whereNotNull('code')->orderBy('code')->get()->filter->estModifiable(),
        ]));
    }

    /**
     * Journal des imports confirmés en tableau de données : complet (onglet Imports) ou réduit (« Derniers imports » du
     * tableau de bord). Créées, mises à jour, erreurs (toujours 0 : un fichier en erreur n'est jamais confirmé), statut.
     */
    public static function journal(bool $complet = true, string $id = 'imports'): Tableau
    {
        $i = __('admin.imports');
        $c = $i['colonnes'];
        $codes = null; // Codes des sites, lus une seule fois.
        $site = function (AuditLog $e) use (&$codes) {
            $codes ??= Site::query()->pluck('code', 'id');

            return $e->action === 'boutiques.importees' ? ($codes[$e->entite_id] ?? '—') : trans_choice('admin.imports.n_sites', $e->nouvelle_valeur['sites_crees'] ?? 0
                + ($e->nouvelle_valeur['sites_mis_a_jour'] ?? 0) + ($e->nouvelle_valeur['sites_inchanges'] ?? 0));
        };
        $nombre = fn (string ...$cles) => fn (AuditLog $e) => (int) collect($cles)->sum(fn ($k) => $e->nouvelle_valeur[$k] ?? 0);

        return Tableau::pour(AuditLog::query()->whereIn('action', array_keys(self::ACTIONS))->with('acteur'), $id)
            ->colonnes([
                Colonne::make('date', $c['date'])->triable('created_at')->classe('whitespace-nowrap')->valeur(fn (AuditLog $e) => $e->created_at->format('d/m/Y H:i')),
                Colonne::make('fichier', $c['fichier'])->triable('nouvelle_valeur')->recherchable('nouvelle_valeur')->classe('font-semibold')
                    ->valeur(fn (AuditLog $e) => $complet && ! empty($e->nouvelle_valeur['chemin'])
                        ? new HtmlString('<a href="'.e(route('admin.referencement.imports.fichier', $e)).'" class="underline underline-offset-2" title="'.e($i['telecharger']).'">'.e($e->nouvelle_valeur['fichier'] ?? '—').'</a>')
                        : ($e->nouvelle_valeur['fichier'] ?? '—'))
                    ->texte(fn (AuditLog $e) => $e->nouvelle_valeur['fichier'] ?? null),
                Colonne::make('site', $c['site'])->triable('entite_id')->valeur($site),
                $complet ? Colonne::make('type', $c['type'])->triable('action')->valeur(fn (AuditLog $e) => $i['types'][$e->action] ?? $e->action) : null,
                $complet ? Colonne::make('auteur', $c['auteur'])->recherchable(fn ($q, $terme) => $q->whereHas('acteur', fn ($a) => $a->where('name', 'like', '%'.$terme.'%')))
                    ->valeur(fn (AuditLog $e) => $e->acteur?->name ?? '—') : null,
                Colonne::make('creees', $c['creees'])->numerique()->valeur($nombre('creees', 'sites_crees')),
                Colonne::make('mises_a_jour', $c['mises_a_jour'])->numerique()->valeur($nombre('mises_a_jour', 'sites_mis_a_jour')),
                Colonne::make('erreurs', $c['erreurs'])->numerique()->valeur(fn () => 0),
                Colonne::make('statut', $c['statut'])->valeur(fn () => BadgeStatut::html($i['confirme']))->texte(fn () => $i['confirme']),
            ])
            ->filtres($complet ? [Filtre::make('type', $c['type'], $i['types'], 'action')] : [])
            ->triParDefaut('date', 'desc')
            ->unite($i['unite'])
            ->lignesParDefaut($complet ? Tableau::LIGNES_DEFAUT : 10)
            ->when($complet, fn (Tableau $t) => $t->export('Raaga_journal-imports'));
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
