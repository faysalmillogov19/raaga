<?php

namespace App\Http\Requests\Admin;

use App\Models\CampagneBoutiques;
use App\Models\Duree;
use App\Models\MoyenPaiement;
use App\Models\ParametresPaiements;
use App\Models\TypeBoutique;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Paramètres d'une campagne de réservation (premier arrivé) : période (date et heure), site(s) concerné(s), nombre
 * maximum de boutiques par personne, moyens de paiement (le délai de paiement de la caution est un des « Délais de
 * paiement »), délai de relance de la liste d'attente générale. Les montants ne
 * se saisissent pas : ils viennent de la grille tarifaire confirmée de chaque site.
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
            'canaux_actifs' => ['required', 'array', 'min:1'],
            'canaux_actifs.*' => [Rule::in(MoyenPaiement::proposables()->pluck('code')->all())],
            'sites' => ['required', 'array', 'min:1'],
            'sites.*' => ['integer', Rule::exists('sites', 'id')->where('statut', 'CONFIRME')],
            // Types proposés : au moins un, parmi les types actifs, validés et attribuables ; jamais la chambre froide (CF).
            'types' => ['required', 'array', 'min:1'],
            'types.*' => ['integer', Rule::in(TypeBoutique::query()->proposables()->pluck('id')->all())],
            // Limite par type coché : nombre maximum d'emplacements par personne, au moins 1.
            'limites' => ['nullable', 'array'],
            ...collect((array) $this->input('types'))->mapWithKeys(fn ($id) => ['limites.'.(int) $id => ['required', 'integer', 'min:1', 'max:1000']])->all(),
            // Délai de relance de la liste d'attente générale.
            ...collect(array_keys(CampagneBoutiques::DELAIS_LISTES))->flatMap(fn (string $d) => [
                // Vide : délai par défaut (CampagneBoutiques::DELAIS_LISTES).
                'delai_'.$d.'_valeur' => ['nullable', 'integer', 'min:1', 'max:999999'],
                'delai_'.$d.'_duree' => ['required_with:delai_'.$d.'_valeur', 'nullable', Rule::in(Duree::toutes()->pluck('code')->all())],
            ])->all(),
        ];
    }

    public function messages(): array
    {
        return [
            'sites.required' => __('admin.boutiques.erreurs.sites'),
            'sites.*.exists' => __('admin.boutiques.erreurs.sites'),
            'types.required' => __('admin.boutiques.erreurs.types'),
            'types.*.in' => __('admin.boutiques.erreurs.types'),
            'limites.*.required' => __('admin.boutiques.erreurs.limite'),
            'limites.*.min' => __('admin.boutiques.erreurs.limite'),
            'limites.*.integer' => __('admin.boutiques.erreurs.limite'),
        ];
    }

    public function attributes(): array
    {
        return __('admin.boutiques.champs');
    }

    /**
     * @return array<string, mixed>
     *
     * @throws ValidationException
     */
    public function parametres(): array
    {
        // Délais : durée enregistrée par son identifiant, valeur convertie en secondes calculée et stockée.
        $delais = [];
        foreach (array_keys(CampagneBoutiques::DELAIS_LISTES) as $d) {
            if ($this->validated('delai_'.$d.'_valeur') === null) {
                $delais += ['delai_'.$d.'_valeur' => null, 'delai_'.$d.'_duree_id' => null, 'delai_'.$d.'_secondes' => null];

                continue;
            }
            $duree = Duree::parCode($this->validated('delai_'.$d.'_duree'));
            $secondes = $duree->enSecondes((int) $this->validated('delai_'.$d.'_valeur'));
            if ($secondes > ParametresPaiements::SECONDES_MAX) {
                throw ValidationException::withMessages(['delai_'.$d.'_valeur' => __('admin.delais.erreurs.trop_long')]);
            }
            $delais += ['delai_'.$d.'_valeur' => (int) $this->validated('delai_'.$d.'_valeur'), 'delai_'.$d.'_duree_id' => $duree->id, 'delai_'.$d.'_secondes' => $secondes];
        }

        return [...$this->safe()->only(['nom', 'description', 'date_ouverture', 'date_cloture', 'canaux_actifs']), ...$delais,
            'sites' => array_values(array_unique(array_map('intval', (array) $this->validated('sites')))),
            'types' => $types = array_values(array_unique(array_map('intval', (array) $this->validated('types')))),
            'limites' => (object) collect($types)->mapWithKeys(fn (int $id) => [(string) $id => (int) $this->validated('limites.'.$id)])->all(),
        ];
    }
}
