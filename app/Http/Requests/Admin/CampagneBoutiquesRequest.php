<?php

namespace App\Http\Requests\Admin;

use App\Enums\CanalPaiement;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Paramètres d'une campagne d'attribution : période, paiement, plafond par personne, et types de boutiques
 * proposés sur chaque site confirmé. Les montants ne se saisissent pas : ils viennent de la grille tarifaire.
 */
class CampagneBoutiquesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Droit vérifié par le contrôleur (Gate::authorize).
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
            'max_boutiques_par_personne' => ['nullable', 'integer', 'min:1', 'max:1000'],
            'lots' => ['required', 'array'],
            'lots.*' => ['array'],
            'lots.*.*' => ['boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'lots.required' => __('admin.boutiques.erreurs.categories'),
        ];
    }

    public function attributes(): array
    {
        return __('admin.boutiques.champs');
    }

    /** @return array<string, mixed> */
    public function parametres(): array
    {
        return $this->safe()->only(['nom', 'description', 'date_ouverture', 'date_cloture', 'delai_paiement_heures', 'canaux_actifs', 'max_boutiques_par_personne']);
    }

    /** Types retenus par site : [site_id][type_id] => coché. @return array<int|string, array<int|string, mixed>> */
    public function selection(): array
    {
        return (array) $this->validated('lots');
    }
}
