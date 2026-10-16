<?php

namespace App\Http\Requests\Admin;

use App\Models\MoyenPaiement;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Paramètres d'une campagne de réservation (premier arrivé) : période (date et heure), site(s) concerné(s), nombre
 * maximum de boutiques par personne, délai de paiement de la caution, moyens de paiement. Les montants ne se saisissent
 * pas : ils viennent de la grille tarifaire confirmée de chaque site.
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
            'canaux_actifs.*' => [Rule::in(MoyenPaiement::proposables()->pluck('code')->all())],
            'max_boutiques_par_personne' => ['required', 'integer', 'min:1', 'max:1000'],
            'sites' => ['required', 'array', 'min:1'],
            'sites.*' => ['integer', Rule::exists('sites', 'id')->where('statut', 'CONFIRME')],
        ];
    }

    public function messages(): array
    {
        return [
            'sites.required' => __('admin.boutiques.erreurs.sites'),
            'sites.*.exists' => __('admin.boutiques.erreurs.sites'),
        ];
    }

    public function attributes(): array
    {
        return __('admin.boutiques.champs');
    }

    /** @return array<string, mixed> */
    public function parametres(): array
    {
        return [...$this->safe()->only(['nom', 'description', 'date_ouverture', 'date_cloture', 'delai_paiement_heures', 'canaux_actifs', 'max_boutiques_par_personne']),
            'sites' => array_values(array_unique(array_map('intval', (array) $this->validated('sites')))),
        ];
    }
}
