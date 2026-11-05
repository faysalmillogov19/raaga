<?php

namespace App\Http\Controllers\Admin;

use App\Enums\Droit;
use App\Http\Controllers\Controller;
use App\Models\Greffe;
use App\Models\User;
use App\Services\Audit\Journal;
use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * Greffes d'immatriculation au RCCM (réunion du 05/11/2026) : le SUPER_ADMIN ajoute, renomme, classe, active ou
 * désactive (jamais « Autre (préciser) », jamais de suppression) ; l'ADMIN consulte la liste et les greffes saisis en
 * « Autre » par les titulaires. Le SUPER_ADMIN peut ajouter un greffe « Autre » à la liste : les comptes qui l'avaient
 * saisi y sont rattachés. Chaque action est journalisée.
 */
class GreffesController extends Controller
{
    public function __construct(private readonly Journal $journal) {}

    public function index(): View
    {
        Gate::authorize(Droit::GreffesVoir->value);
        $comptes = User::query()->whereNotNull('rccm_greffe_id')->groupBy('rccm_greffe_id')->selectRaw('rccm_greffe_id, count(*) as n')->pluck('n', 'rccm_greffe_id');

        return view('admin.greffes.index', [
            'greffes' => Greffe::query()->orderByRaw('code = ? asc', [Greffe::AUTRE])->orderBy('ordre')->orderBy('libelle')->get(),
            'comptes' => $comptes,
            // Précisions saisies avec « Autre (préciser) » : valeur et nombre de comptes, pour compléter la liste.
            'autres' => User::query()->where('rccm_greffe_id', Greffe::idAutre())->whereNotNull('rccm_greffe_autre')
                ->groupBy('rccm_greffe_autre')->selectRaw('rccm_greffe_autre as valeur, count(*) as n')->orderByDesc('n')->orderBy('rccm_greffe_autre')->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        Gate::authorize(Droit::GreffesGerer->value);
        $donnees = $this->valider($request, null);
        $greffe = $this->creer($donnees['libelle'], $donnees['ordre'] ?? null, $request->user());

        return redirect()->route('admin.greffes')->avecSucces('admin.greffes.messages.cree', ['libelle' => $greffe->libelle]);
    }

    public function update(Request $request, Greffe $greffe): RedirectResponse
    {
        Gate::authorize(Droit::GreffesGerer->value);
        abort_if($greffe->code === Greffe::AUTRE, 409);
        $donnees = $this->valider($request, $greffe);
        $donnees['ordre'] ??= $greffe->ordre;
        $avant = $greffe->only(['libelle', 'ordre']);
        $greffe->fill($donnees);
        if (! $greffe->isDirty()) {
            return redirect()->route('admin.greffes')->with('info', __('admin.greffes.messages.aucun_changement'));
        }
        $greffe->save();
        $this->journal->ecrire($request->user(), 'greffe.modifie', 'Greffe', $greffe->id, $avant, $greffe->only(['libelle', 'ordre']));

        return redirect()->route('admin.greffes')->avecSucces('admin.greffes.messages.modifie', ['libelle' => $greffe->libelle]);
    }

    public function activer(Request $request, Greffe $greffe): RedirectResponse
    {
        return $this->activation($request, $greffe, true);
    }

    public function desactiver(Request $request, Greffe $greffe): RedirectResponse
    {
        return $this->activation($request, $greffe, false);
    }

    /** Précision « Autre » ajoutée à la liste : nouveau greffe, comptes qui l'avaient saisie rattachés. */
    public function ajouterAutre(Request $request): RedirectResponse
    {
        Gate::authorize(Droit::GreffesGerer->value);
        $valeur = Str::squish((string) $request->input('valeur'));
        $comptes = User::query()->where('rccm_greffe_id', Greffe::idAutre())->where('rccm_greffe_autre', $request->input('valeur'));
        abort_if($valeur === '' || ! (clone $comptes)->exists(), 404);
        $request->merge(['libelle' => $valeur]);
        $this->valider($request, null);

        $greffe = DB::transaction(function () use ($valeur, $comptes, $request) {
            $greffe = $this->creer($valeur, null, $request->user());
            $n = $comptes->update(['rccm_greffe_id' => $greffe->id, 'rccm_greffe_autre' => null]);
            $this->journal->ecrire($request->user(), 'greffe.autre_ajoute', 'Greffe', $greffe->id, null, ['libelle' => $greffe->libelle, 'comptes' => $n]);

            return $greffe;
        });

        return redirect()->route('admin.greffes')->avecSucces('admin.greffes.messages.autre_ajoute', ['libelle' => $greffe->libelle]);
    }

    private function activation(Request $request, Greffe $greffe, bool $actif): RedirectResponse
    {
        Gate::authorize(Droit::GreffesGerer->value);
        if (! $actif && $greffe->code === Greffe::AUTRE) {
            return redirect()->route('admin.greffes')->with('erreur', __('admin.greffes.regles.autre'));
        }
        if ($greffe->actif !== $actif) {
            $greffe->forceFill(['actif' => $actif])->save();
            $this->journal->ecrire($request->user(), $actif ? 'greffe.active' : 'greffe.desactive', 'Greffe', $greffe->id, ['actif' => ! $actif], ['actif' => $actif]);
        }

        return redirect()->route('admin.greffes')->avecSucces($actif ? 'admin.greffes.messages.active' : 'admin.greffes.messages.desactive', ['libelle' => $greffe->libelle]);
    }

    private function creer(string $libelle, ?int $ordre, User $acteur): Greffe
    {
        $greffe = Greffe::query()->create(['libelle' => $libelle, 'code' => $this->code($libelle), 'actif' => true,
            'ordre' => $ordre ?? (int) Greffe::query()->where('code', '!=', Greffe::AUTRE)->max('ordre') + 1]);
        $this->journal->ecrire($acteur, 'greffe.cree', 'Greffe', $greffe->id, null, $greffe->only(['code', 'libelle', 'ordre']));

        return $greffe;
    }

    /** @return array{libelle: string, ordre?: ?int} */
    private function valider(Request $request, ?Greffe $greffe): array
    {
        $request->merge(['libelle' => is_string($request->input('libelle')) ? Str::squish($request->input('libelle')) : $request->input('libelle')]);

        return $request->validate([
            'libelle' => ['required', 'string', 'min:3', 'max:160', $this->libelleUnique($greffe)],
            'ordre' => ['nullable', 'integer', 'min:0', 'max:998'],
        ], [], __('admin.greffes.colonnes'));
    }

    /** Libellé unique, sans tenir compte des majuscules. */
    private function libelleUnique(?Greffe $greffe): Closure
    {
        return function (string $attribut, mixed $valeur, Closure $echec) use ($greffe) {
            $existe = Greffe::query()->whereRaw('lower(libelle) = ?', [mb_strtolower((string) $valeur)])->when($greffe, fn ($q) => $q->whereKeyNot($greffe->id))->exists();
            if ($existe) {
                $echec(__('admin.greffes.regles.libelle_existe'));
            }
        };
    }

    /** Code interne tiré du libellé, rendu unique par un suffixe numérique. */
    private function code(string $libelle): string
    {
        $base = Str::limit(Str::upper(Str::slug($libelle, '_')), 34, '') ?: 'GREFFE';
        $code = $base;
        for ($n = 2; Greffe::query()->where('code', $code)->exists(); $n++) {
            $code = $base.'_'.$n;
        }

        return $code;
    }
}
