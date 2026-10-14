<?php

namespace App\Http\Controllers\Admin;

use App\Enums\Droit;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Site;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Imports du référencement : boutons d'import (sites, boutiques d'un site, boutiques de plusieurs sites) pour le
 * GESTIONNAIRE DES SITES ET BOUTIQUES, et journal de tous les imports avec le fichier d'origine téléchargeable.
 */
class ImportsController extends Controller
{
    /** Actions du journal d'audit qui sont des imports : [action => [type, configuration du stockage]]. */
    public const ACTIONS = ['sites.importes' => 'referentiel_sites', 'boutiques.importees' => 'referentiel_boutiques'];

    private const PAR_PAGE = 30;

    public function index(): View
    {
        Gate::authorize(Droit::ImportsVoir->value);
        $journal = AuditLog::query()->whereIn('action', array_keys(self::ACTIONS))->with('acteur')->latest('id')->paginate(self::PAR_PAGE);

        return view('admin.referencement.imports', [
            'journal' => $journal,
            'sites' => Site::query()->whereIn('id', $journal->getCollection()->where('action', 'boutiques.importees')->pluck('entite_id'))->pluck('code', 'id'),
            'sitesModifiables' => Site::query()->whereNotNull('code')->orderBy('code')->get()->filter->estModifiable(),
        ]);
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
