<?php

namespace App\Http\Requests\Admin;

use App\Enums\CanalPaiement;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Paramètres d'une campagne d'attribution des boutiques, avec ses catégories (libellé, nombre, caution). */
class CampagneBoutiquesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Droit vérifié par le contrôleur (Gate::authorize).
    }

    protected function prepareForValidation(): void
    {
        // Lignes de catégorie entièrement vides (ajoutées puis laissées) : ignorées.
        $this->merge(['categories' => array_values(array_filter(
            (array) $this->input('categories', []),
            fn ($c) => is_array($c) && collect($c)->filter(fn ($v) => filled($v))->isNotEmpty(),
        ))]);
    }

    public function rules(): array
    {
        return [
            'nom' => ['required', 'string', 'max:150'],
            'description' => ['nullable', 'string', 'max:2000'],
            'date_ouverture' => ['required', 'date'],
            'date_cloture' => ['required', 'date', 'after:date_ouverture'],
            'delai_paiement_heures' => ['required', 'integer', 'min:1', 'max:720'],
            'canaux_actifs' => ['required', 'array', 'min:1'],
            'canaux_actifs.*' => [Rule::enum(CanalPaiement::class)],
            'categories' => ['required', 'array', 'min:1', 'max:20'],
            'categories.*.libelle' => ['required', 'string', 'max:80'],
            'categories.*.description' => ['nullable', 'string', 'max:300'],
            'categories.*.nombre_boutiques' => ['required', 'integer', 'min:1', 'max:100000'],
            'categories.*.caution' => ['required', 'integer', 'min:1', 'max:1000000000'],
        ];
    }

    public function messages(): array
    {
        return [
            'categories.required' => __('admin.boutiques.erreurs.categories'),
            'categories.min' => __('admin.boutiques.erreurs.categories'),
            'categories.*.*' => __('admin.boutiques.erreurs.categorie_complete'),
        ];
    }

    public function attributes(): array
    {
        return __('admin.boutiques.champs');
    }

    /** @return array<string, mixed> */
    public function parametres(): array
    {
        return $this->safe()->only(['nom', 'description', 'date_ouverture', 'date_cloture', 'delai_paiement_heures', 'canaux_actifs']);
    }

    /** @return list<array{libelle: string, description: ?string, nombre_boutiques: int, caution: int}> */
    public function categories(): array
    {
        return array_map(fn (array $c) => [
            'libelle' => $c['libelle'],
            'description' => $c['description'] ?? null,
            'nombre_boutiques' => (int) $c['nombre_boutiques'],
            'caution' => (int) $c['caution'],
        ], $this->validated('categories'));
    }
}
