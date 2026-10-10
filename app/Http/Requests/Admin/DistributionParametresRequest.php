<?php

namespace App\Http\Requests\Admin;

use App\Enums\CanalPaiement;
use App\Enums\ModeCalculDividende;
use App\Enums\PayeurFrais;
use App\Enums\TraitementNonReclames;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Paramètres de distribution des dividendes d'un exercice. Le droit est vérifié par le contrôleur.
 */
class DistributionParametresRequest extends FormRequest
{
    /** Canaux de versement possibles pour les dividendes (pas d'espèces). */
    public const CANAUX = [CanalPaiement::OrangeMoney, CanalPaiement::MoovMoney, CanalPaiement::Virement];

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $enum = fn (string $classe) => Rule::in(array_column($classe::cases(), 'value'));
        $canaux = array_map(fn (CanalPaiement $c) => $c->value, self::CANAUX);
        $distribution = $this->route('distribution');

        return [
            'exercice' => ['required', 'integer', 'between:2000,2100', Rule::unique('distributions_dividendes', 'exercice')->ignore($distribution?->id)],
            'ag_date' => ['required', 'date'],
            'ag_pv_numero' => ['required', 'string', 'max:60'],
            'pv' => ['nullable', 'file', 'mimes:pdf', 'max:10240'],
            'resultat_distribuable' => ['required', 'integer', 'min:0'],
            'mode_calcul' => ['required', $enum(ModeCalculDividende::class)],
            'montant_global' => ['nullable', 'required_if:mode_calcul,MONTANT_GLOBAL', 'integer', 'min:1', 'lte:resultat_distribuable'],
            'dividende_brut_par_action' => ['nullable', 'required_if:mode_calcul,PAR_ACTION', 'integer', 'min:1'],
            'date_arrete' => ['required', 'date', 'after_or_equal:ag_date'],
            'date_mise_en_paiement' => ['required', 'date', 'after:date_arrete'],
            'taux_irvm' => ['required', 'numeric', 'min:0', 'max:100', 'decimal:0,2'],
            'frais_supportes_par' => ['required', $enum(PayeurFrais::class)],
            'seuil_minimal' => ['required', 'integer', 'min:0'],
            'canaux_autorises' => ['required', 'array', 'min:1'],
            'canaux_autorises.*' => [Rule::in($canaux)],
            'canal_defaut' => ['required', Rule::in($this->input('canaux_autorises', []))],
            'tentatives' => ['required', 'integer', 'min:0', 'max:10'],
            'delai_tentatives_heures' => ['required', 'integer', 'min:1', 'max:720'],
            'conservation_non_reclames_mois' => ['required', 'integer', 'min:1', 'max:600'],
            'traitement_non_reclames' => ['required', $enum(TraitementNonReclames::class)],
        ];
    }

    /**
     * Valeurs prêtes à enregistrer (taux en points de base, champ inutile selon le mode remis à null).
     *
     * @return array<string, mixed>
     */
    public function parametres(): array
    {
        $v = $this->safe()->except(['pv', 'taux_irvm']);
        $v['taux_irvm_pb'] = (int) round(((float) $this->input('taux_irvm')) * 100);
        $v['canaux_autorises'] = array_values(array_unique($v['canaux_autorises']));

        if ($v['mode_calcul'] === ModeCalculDividende::MontantGlobal->value) {
            $v['dividende_brut_par_action'] = null;
        } else {
            $v['montant_global'] = null;
        }

        return $v;
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return __('admin.dividendes.champs');
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'exercice.unique' => __('admin.dividendes.erreurs.exercice_existe'),
            'montant_global.lte' => __('admin.dividendes.erreurs.montant_global'),
            'date_arrete.after_or_equal' => __('admin.dividendes.erreurs.arrete_apres_ag'),
            'date_mise_en_paiement.after' => __('admin.dividendes.erreurs.paiement_apres_arrete'),
            'canal_defaut.in' => __('admin.dividendes.erreurs.canal_defaut'),
            'pv.mimes' => __('admin.dividendes.erreurs.pv_pdf'),
            'pv.max' => __('admin.dividendes.erreurs.pv_taille'),
            '*.required_if' => __('admin.tranches.erreurs.requis_si'),
        ];
    }
}
