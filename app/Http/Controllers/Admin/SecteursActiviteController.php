<?php

namespace App\Http\Controllers\Admin;

use App\Enums\Droit;
use App\Http\Controllers\Controller;
use App\Models\FamilleSecteur;
use App\Models\SecteurActivite;
use App\Models\User;
use App\Services\Audit\Journal;
use App\Support\BadgeStatut;
use App\Support\Tableau\Colonne;
use App\Support\Tableau\Filtre;
use App\Support\Tableau\ResultatTableau;
use App\Support\Tableau\Tableau;
use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Secteurs d'activité des commerçants (SUPER_ADMIN, 04/11/2026) : ajout, modification (libellé, famille, ordre),
 * activation et désactivation, sans validation. Jamais de suppression : un secteur désactivé n'est plus proposé à la
 * réservation, les commerçants qui l'ont choisi le gardent. « Autre activité » n'est jamais désactivé. Code interne
 * calculé à partir du libellé, jamais modifié. Chaque action est journalisée.
 */
class SecteursActiviteController extends Controller
{
    public function __construct(private readonly Journal $journal) {}

    public function index(Request $request): Response|StreamedResponse|View
    {
        Gate::authorize(Droit::SecteursGerer->value);
        $s = __('admin.secteurs');
        $familles = FamilleSecteur::query()->orderBy('ordre')->pluck('libelle', 'id')->all();
        $commercants = User::query()->selectRaw('count(*)')->whereColumn('users.secteur_activite_id', 'secteurs_activite.id');

        return Tableau::pour(SecteurActivite::query()->select('secteurs_activite.*')->with('famille'), 'secteurs')
            ->colonnes([
                Colonne::make('libelle', $s['colonnes']['libelle'])->triable()->recherchable()->classe('font-semibold'),
                Colonne::make('famille', $s['colonnes']['famille'])->valeur(fn (SecteurActivite $e) => $e->famille?->libelle)
                    ->triable(fn ($q, string $sens) => $q->orderBy(FamilleSecteur::query()->select('ordre')->whereColumn('familles_secteurs.id', 'secteurs_activite.famille_secteur_id'), $sens)),
                Colonne::make('ordre', $s['colonnes']['ordre'])->numerique()->triable(),
                Colonne::make('commercants', $s['colonnes']['commercants'])->numerique()->triable('commercants_count')
                    ->avec(fn ($q) => $q->addSelect(['commercants_count' => $commercants]))
                    ->valeur(fn (SecteurActivite $e) => format_nombre((int) $e->commercants_count)),
                Colonne::make('actif', $s['colonnes']['statut'])->triable()
                    ->valeur(fn (SecteurActivite $e) => BadgeStatut::html($e->actif ? $s['actif'] : $s['desactive']))
                    ->texte(fn (SecteurActivite $e) => $e->actif ? $s['actif'] : $s['desactive']),
                Colonne::make('actions', $s['colonnes']['actions'])->vue('admin.secteurs._actions', 'secteur')->exportable(false),
            ])
            ->filtres([
                Filtre::make('famille', $s['colonnes']['famille'], $familles, 'famille_secteur_id'),
                Filtre::make('actif', $s['colonnes']['statut'], [1 => $s['actif'], 0 => $s['desactive']], 'actif'),
            ])
            ->triParDefaut('famille')
            ->departage(fn ($q) => $q->orderBy('secteurs_activite.ordre')->orderBy('secteurs_activite.id'))
            // Tous les secteurs sur une page (26 à l'installation).
            ->lignesParDefaut(50)
            ->export('Raaga_secteurs_activite')
            ->repondre($request, fn (ResultatTableau $t) => view('admin.secteurs.index', ['tableau' => $t, 'familles' => $familles]));
    }

    public function store(Request $request): RedirectResponse
    {
        Gate::authorize(Droit::SecteursGerer->value);
        $donnees = $this->valider($request, null);
        $donnees['ordre'] ??= (int) SecteurActivite::query()->where('famille_secteur_id', $donnees['famille_secteur_id'])->max('ordre') + 1;
        $secteur = SecteurActivite::query()->create([...$donnees, 'code' => $this->code($donnees['libelle']), 'actif' => true]);
        $this->journal->ecrire($request->user(), 'secteur_activite.cree', 'SecteurActivite', $secteur->id, null, $secteur->only(['code', 'libelle', 'famille_secteur_id', 'ordre']));

        return redirect()->route('admin.secteurs')->avecSucces('admin.secteurs.messages.cree', ['libelle' => $secteur->libelle]);
    }

    public function update(Request $request, SecteurActivite $secteur): RedirectResponse
    {
        Gate::authorize(Droit::SecteursGerer->value);
        $donnees = $this->valider($request, $secteur);
        $donnees['ordre'] ??= $secteur->ordre;
        $avant = $secteur->only(array_keys($donnees));
        $secteur->fill($donnees);
        if (! $secteur->isDirty()) {
            return redirect()->route('admin.secteurs')->with('info', __('admin.secteurs.messages.aucun_changement'));
        }
        $secteur->save();
        $this->journal->ecrire($request->user(), 'secteur_activite.modifie', 'SecteurActivite', $secteur->id, $avant, $secteur->only(array_keys($donnees)));

        return redirect()->route('admin.secteurs')->avecSucces('admin.secteurs.messages.modifie', ['libelle' => $secteur->libelle]);
    }

    public function activer(Request $request, SecteurActivite $secteur): RedirectResponse
    {
        return $this->activation($request, $secteur, true);
    }

    public function desactiver(Request $request, SecteurActivite $secteur): RedirectResponse
    {
        return $this->activation($request, $secteur, false);
    }

    private function activation(Request $request, SecteurActivite $secteur, bool $actif): RedirectResponse
    {
        Gate::authorize(Droit::SecteursGerer->value);
        if (! $actif && $secteur->code === SecteurActivite::AUTRE) {
            return redirect()->route('admin.secteurs')->with('erreur', __('admin.secteurs.regles.autre'));
        }
        if ($secteur->actif !== $actif) {
            $secteur->forceFill(['actif' => $actif])->save();
            $this->journal->ecrire($request->user(), $actif ? 'secteur_activite.active' : 'secteur_activite.desactive', 'SecteurActivite', $secteur->id,
                ['actif' => ! $actif], ['actif' => $actif]);
        }

        return redirect()->route('admin.secteurs')->avecSucces($actif ? 'admin.secteurs.messages.active' : 'admin.secteurs.messages.desactive', ['libelle' => $secteur->libelle]);
    }

    /** @return array{famille_secteur_id: int, libelle: string, ordre: ?int} */
    private function valider(Request $request, ?SecteurActivite $secteur): array
    {
        $request->merge(['libelle' => is_string($request->input('libelle')) ? Str::squish($request->input('libelle')) : $request->input('libelle')]);

        return $request->validate([
            'libelle' => ['required', 'string', 'max:160', $this->libelleUnique($secteur)],
            'famille_secteur_id' => ['required', 'integer', Rule::exists('familles_secteurs', 'id')],
            'ordre' => ['nullable', 'integer', 'min:0', 'max:9999'],
        ], [], __('admin.secteurs.colonnes'));
    }

    /** Libellé unique, sans tenir compte des majuscules. */
    private function libelleUnique(?SecteurActivite $secteur): Closure
    {
        return function (string $attribut, mixed $valeur, Closure $echec) use ($secteur) {
            $existe = SecteurActivite::query()->whereRaw('lower(libelle) = ?', [mb_strtolower((string) $valeur)])
                ->when($secteur, fn ($q) => $q->whereKeyNot($secteur->id))->exists();
            if ($existe) {
                $echec(__('admin.secteurs.regles.libelle_existe'));
            }
        };
    }

    /** Code interne tiré du libellé (« Vente de pagnes » : VENTE_DE_PAGNES), rendu unique par un suffixe numérique. */
    private function code(string $libelle): string
    {
        $base = Str::limit(Str::upper(Str::slug($libelle, '_')), 34, '') ?: 'SECTEUR';
        $code = $base;
        for ($n = 2; SecteurActivite::query()->where('code', $code)->exists(); $n++) {
            $code = $base.'_'.$n;
        }

        return $code;
    }
}
