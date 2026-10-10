<?php

namespace App\Http\Requests\Admin;

use App\Enums\ModeInscriptionA;
use App\Services\Institutionnels\AppelsDeFonds;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Paramètres d'une convention d'actions A (saisie du RÉGULATEUR). Contrôles : montant engagé =
 * nombre d'actions × prix unitaire ; somme des appels de fonds = montant engagé.
 */
class ConventionParametresRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $convention = $this->route('convention');

        return [
            'institution_id' => [$convention ? 'prohibited' : 'required', 'integer', 'exists:institutions,id'],
            'reference' => ['required', 'string', 'max:60', Rule::unique('conventions', 'reference')->ignore($convention?->id)],
            'nb_actions' => ['required', 'integer', 'min:1'],
            'prix_unitaire' => ['required', 'integer', 'min:1'],
            'montant_engage' => ['required', 'integer', 'min:1'],
            'mode_inscription' => ['required', Rule::enum(ModeInscriptionA::class)],
            'droits_particuliers' => ['nullable', 'string', 'max:5000'],
            'echeancier' => ['required', 'array', 'min:1', 'max:60'],
            'echeancier.*.libelle' => ['required', 'string', 'max:160'],
            'echeancier.*.echeance' => ['required', 'date'],
            'echeancier.*.type' => ['required', Rule::in(['montant', 'pourcentage'])],
            'echeancier.*.valeur' => ['required', 'numeric', 'gt:0'],
            'pdf' => ['nullable', 'file', 'mimes:pdf', 'max:20480'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $v) {
            if ($v->errors()->isNotEmpty()) {
                return;
            }

            $attendu = (int) $this->input('nb_actions') * (int) $this->input('prix_unitaire');
            if ((int) $this->input('montant_engage') !== $attendu) {
                $v->errors()->add('montant_engage', __('institutionnel.regles.montant_engage', ['attendu' => format_fcfa($attendu)]));

                return;
            }

            $total = array_sum(array_column($this->echeancierCalcule(), 'montant'));
            if ($total !== (int) $this->input('montant_engage')) {
                $v->errors()->add('echeancier', __('institutionnel.regles.echeancier_total', [
                    'total' => format_fcfa($total), 'engage' => format_fcfa((int) $this->input('montant_engage')),
                ]));
            }
        }];
    }

    public function attributes(): array
    {
        $c = __('institutionnel.bo.conventions');

        return [
            ...$c['champs'],
            'echeancier.*.libelle' => $c['libelle'],
            'echeancier.*.echeance' => $c['echeance'],
            'echeancier.*.valeur' => $c['valeur'],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function echeancierCalcule(): array
    {
        return AppelsDeFonds::calculerEcheancier((int) $this->input('montant_engage'), array_values((array) $this->input('echeancier', [])));
    }

    /**
     * Valeurs à enregistrer (le PDF est traité à part).
     *
     * @return array<string, mixed>
     */
    public function parametres(): array
    {
        $valeurs = $this->safe()->only(['reference', 'nb_actions', 'prix_unitaire', 'montant_engage', 'mode_inscription', 'droits_particuliers']);

        return [
            ...$valeurs,
            'nb_actions' => (int) $valeurs['nb_actions'],
            'prix_unitaire' => (int) $valeurs['prix_unitaire'],
            'montant_engage' => (int) $valeurs['montant_engage'],
            'droits_particuliers' => trim((string) ($valeurs['droits_particuliers'] ?? '')) ?: null,
            'echeancier' => $this->echeancierCalcule(),
        ];
    }
}
