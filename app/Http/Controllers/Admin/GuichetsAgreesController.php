<?php

namespace App\Http\Controllers\Admin;

use App\Enums\Droit;
use App\Http\Controllers\Controller;
use App\Models\Commune;
use App\Models\Guichet;
use App\Services\Audit\Journal;
use App\Support\BadgeStatut;
use App\Support\Tableau\Colonne;
use App\Support\Tableau\Filtre;
use App\Support\Tableau\ResultatTableau;
use App\Support\Tableau\Tableau;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Guichets agréés (SUPER_ADMIN, 04/11/2026) : ajout, modification, activation, désactivation et suppression, sans
 * validation. Chaque guichet est rattaché à sa commune ; seuls les guichets actifs paraissent sur « Trouver un
 * guichet ». Chaque action est journalisée.
 */
class GuichetsAgreesController extends Controller
{
    private const CHAMPS = ['nom', 'commune_id', 'adresse', 'horaires', 'telephone'];

    public function __construct(private readonly Journal $journal) {}

    public function index(Request $request): Response|StreamedResponse|View
    {
        Gate::authorize(Droit::GuichetsGerer->value);
        $g = __('admin.guichets_agrees');

        return Tableau::pour(Guichet::query()->select('guichets.*')->with('commune.province.region'), 'guichets')
            ->colonnes([
                Colonne::make('nom', $g['colonnes']['nom'])->triable()->recherchable()->classe('font-semibold'),
                Colonne::make('commune', $g['colonnes']['commune'])->valeur(fn (Guichet $e) => $e->commune?->libelleComplet())
                    ->triable(fn ($q, string $sens) => $q->orderBy(Commune::query()->select('nom')->whereColumn('communes.id', 'guichets.commune_id'), $sens))
                    ->recherchable(fn ($q, string $terme) => $q->whereHas('commune', fn ($c) => $c->whereContient('nom', $terme))),
                Colonne::make('adresse', $g['colonnes']['adresse'])->recherchable(),
                Colonne::make('horaires', $g['colonnes']['horaires']),
                Colonne::make('telephone', $g['colonnes']['telephone'])->recherchable()->classe('whitespace-nowrap'),
                Colonne::make('actif', $g['colonnes']['statut'])->triable()
                    ->valeur(fn (Guichet $e) => BadgeStatut::html($e->actif ? $g['actif'] : $g['desactive']))
                    ->texte(fn (Guichet $e) => $e->actif ? $g['actif'] : $g['desactive']),
                Colonne::make('actions', $g['colonnes']['actions'])->vue('admin.guichets-agrees._actions', 'guichet')->exportable(false),
            ])
            ->filtres([
                Filtre::make('actif', $g['colonnes']['statut'], [1 => $g['actif'], 0 => $g['desactive']], 'actif'),
            ])
            ->triParDefaut('nom')
            ->export('Raaga_guichets')
            ->repondre($request, fn (ResultatTableau $t) => view('admin.guichets-agrees.index', ['tableau' => $t, 'communes' => Commune::parProvince()]));
    }

    public function store(Request $request): RedirectResponse
    {
        Gate::authorize(Droit::GuichetsGerer->value);
        $guichet = Guichet::query()->create([...$this->valider($request), 'actif' => true]);
        $this->journal->ecrire($request->user(), 'guichet.cree', 'Guichet', $guichet->id, null, $guichet->only(self::CHAMPS));

        return redirect()->route('admin.guichets-agrees')->avecSucces('admin.guichets_agrees.messages.cree', ['nom' => $guichet->nom]);
    }

    public function update(Request $request, Guichet $guichet): RedirectResponse
    {
        Gate::authorize(Droit::GuichetsGerer->value);
        $avant = $guichet->only(self::CHAMPS);
        $guichet->fill($this->valider($request));
        if (! $guichet->isDirty()) {
            return redirect()->route('admin.guichets-agrees')->with('info', __('admin.guichets_agrees.messages.aucun_changement'));
        }
        $guichet->save();
        $this->journal->ecrire($request->user(), 'guichet.modifie', 'Guichet', $guichet->id, $avant, $guichet->only(self::CHAMPS));

        return redirect()->route('admin.guichets-agrees')->avecSucces('admin.guichets_agrees.messages.modifie', ['nom' => $guichet->nom]);
    }

    public function activer(Request $request, Guichet $guichet): RedirectResponse
    {
        return $this->activation($request, $guichet, true);
    }

    public function desactiver(Request $request, Guichet $guichet): RedirectResponse
    {
        return $this->activation($request, $guichet, false);
    }

    public function destroy(Request $request, Guichet $guichet): RedirectResponse
    {
        Gate::authorize(Droit::GuichetsGerer->value);
        $this->journal->ecrire($request->user(), 'guichet.supprime', 'Guichet', $guichet->id, $guichet->only(self::CHAMPS));
        $guichet->delete();

        return redirect()->route('admin.guichets-agrees')->avecSucces('admin.guichets_agrees.messages.supprime', ['nom' => $guichet->nom]);
    }

    private function activation(Request $request, Guichet $guichet, bool $actif): RedirectResponse
    {
        Gate::authorize(Droit::GuichetsGerer->value);
        if ($guichet->actif !== $actif) {
            $guichet->forceFill(['actif' => $actif])->save();
            $this->journal->ecrire($request->user(), $actif ? 'guichet.active' : 'guichet.desactive', 'Guichet', $guichet->id, ['actif' => ! $actif], ['actif' => $actif]);
        }

        return redirect()->route('admin.guichets-agrees')->avecSucces($actif ? 'admin.guichets_agrees.messages.active' : 'admin.guichets_agrees.messages.desactive', ['nom' => $guichet->nom]);
    }

    /** @return array{nom: string, commune_id: int, adresse: string, horaires: ?string, telephone: ?string} */
    private function valider(Request $request): array
    {
        $request->merge(collect(['nom', 'adresse', 'horaires', 'telephone'])
            ->mapWithKeys(fn (string $c) => [$c => is_string($v = $request->input($c)) ? (Str::squish($v) ?: null) : $v])->all());

        return $request->validate([
            'nom' => ['required', 'string', 'max:120'],
            'commune_id' => ['required', 'integer', Rule::exists('communes', 'id')],
            'adresse' => ['required', 'string', 'max:300'],
            'horaires' => ['nullable', 'string', 'max:200'],
            'telephone' => ['nullable', 'string', 'max:20', 'regex:/^\+?[0-9 ]{8,19}$/'],
        ], ['telephone.regex' => __('admin.guichets_agrees.regles.telephone')], __('admin.guichets_agrees.colonnes'));
    }
}
