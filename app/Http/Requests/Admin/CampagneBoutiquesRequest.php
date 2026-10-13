<?php

namespace App\Http\Requests\Admin;

use App\Enums\CanalPaiement;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Paramètres d'une campagne d'attribution : période, paiement de la caution, plafond par personne, et
 * caution de chaque lot (site confirmé × type de boutique). Caution vide = type non proposé sur ce site.
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
            'cautions' => ['required', 'array'],
            'cautions.*' => ['array'],
            'cautions.*.*' => ['nullable', 'integer', 'min:1', 'max:1000000000'],
        ];
    }

    public function messages(): array
    {
        return [
            'cautions.required' => __('admin.boutiques.erreurs.categories'),
            'cautions.*.*' => __('admin.boutiques.erreurs.caution'),
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

    /** @return array<int|string, array<int|string, int|string|null>> */
    public function cautions(): array
    {
        return (array) $this->validated('cautions');
    }
}
