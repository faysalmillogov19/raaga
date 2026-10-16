<?php

namespace App\Http\Controllers\Admin;

use App\Enums\Droit;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Support\Tableau\Colonne;
use App\Support\Tableau\ResultatTableau;
use App\Support\Tableau\Tableau;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Historique de mes décisions (VALIDATEUR) : ses confirmations, rejets, autorisations et attributions, tirés du journal
 * d'audit (jamais une autre source) ; tri, recherche et export comme les autres listes.
 */
class HistoriqueDecisionsController extends Controller
{
    /** Fins d'action qui sont des décisions (« site.confirme », « kyc.annulation_rejetee », « reservation_boutique.attribuee »…). */
    public const DECISIONS = ['confirme', 'confirmee', 'rejete', 'rejetee', 'attribuee', 'modification_autorisee', 'modification_refusee'];

    public function __invoke(Request $request): Response|StreamedResponse|View
    {
        Gate::authorize(Droit::AValiderVoir->value);
        $h = __('admin.historique_decisions');

        return Tableau::pour(self::requete($request->user()->id), 'historique')
            ->colonnes([
                Colonne::make('created_at', $h['colonnes']['date'])->triable()->valeur(fn (AuditLog $l) => $l->created_at?->format('d/m/Y H:i')),
                Colonne::make('decision', $h['colonnes']['decision'])->valeur(fn (AuditLog $l) => self::decision($l->action)),
                Colonne::make('action', $h['colonnes']['objet'])->recherchable(['action', 'entite_id'])
                    ->valeur(fn (AuditLog $l) => self::objet($l->action).' n° '.$l->entite_id),
                Colonne::make('motif', $h['colonnes']['motif'])->valeur(fn (AuditLog $l) => $l->nouvelle_valeur['motif'] ?? ''),
            ])
            ->triParDefaut('created_at', 'desc')
            ->unite($h['unite'])
            ->repondre($request, fn (ResultatTableau $t) => view('admin.historique-decisions', ['tableau' => $t]));
    }

    public static function requete(int $userId): Builder
    {
        return AuditLog::query()->where('acteur_id', $userId)
            ->where(fn (Builder $q) => collect(self::DECISIONS)->each(fn (string $fin) => $q->orWhere('action', 'like', '%.'.$fin)->orWhere('action', 'like', '%'.chr(95).$fin)));
    }

    /** « Confirmée », « Rejetée », « Attribuée », « Autorisée », « Refusée ». */
    public static function decision(string $action): string
    {
        $d = __('admin.historique_decisions.decisions');

        return match (true) {
            str_ends_with($action, 'attribuee') => $d['attribuee'],
            str_ends_with($action, 'autorisee') => $d['autorisee'],
            str_ends_with($action, 'refusee') => $d['refusee'],
            str_contains($action, 'rejet') => $d['rejetee'],
            default => $d['confirmee'],
        };
    }

    /** Objet de la décision, d'après le début de l'action (« site », « grille_tarifaire », « kyc »…). */
    public static function objet(string $action): string
    {
        $prefixe = strtok($action, '.');

        return __('admin.historique_decisions.objets')[$prefixe] ?? ucfirst(str_replace(chr(95), ' ', $prefixe));
    }
}
