<?php

namespace App\Http\Requests\Admin;

use App\Enums\MethodeAllocation;
use App\Enums\ModeSaisie;
use App\Enums\PayeurFrais;
use App\Enums\PublicAutorise;
use App\Enums\RegleArret;
use App\Enums\RegleSiEchec;
use App\Enums\TypeFraisSouscription;
use App\Models\MoyenPaiement;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Paramètres de souscription d'une tranche (6 sections). Le droit est vérifié par le contrôleur.
 */
class TrancheParametresRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['souscriptions_multiples' => $this->boolean('souscriptions_multiples')]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $enum = fn (string $classe) => Rule::in(array_column($classe::cases(), 'value'));

        return [
            // Offre
            'nom' => ['required', 'string', 'max:150'],
            'valeur_unitaire' => ['required', 'integer', 'min:1', 'max:100000000'],
            'min_actions_par_souscription' => ['required', 'integer', 'min:1'],
            'plafond_actions_par_personne' => ['nullable', 'integer', 'gte:min_actions_par_souscription'],
            'objectif_montant' => ['required', 'integer', 'gte:valeur_unitaire'],
            'date_ouverture' => ['required', 'date'],
            'date_cloture' => ['required', 'date', 'after:date_ouverture'],
            'publics_autorises' => ['required', 'array', 'min:1'],
            'publics_autorises.*' => [$enum(PublicAutorise::class)],

            // Saisie
            'mode_saisie' => ['required', $enum(ModeSaisie::class)],
            'souscriptions_multiples' => ['boolean'],

            // Sursouscription
            'regle_arret' => ['required', $enum(RegleArret::class)],
            'pourcentage_depassement_max' => ['nullable', 'required_if:regle_arret,DEPASSEMENT_AUTORISE', 'integer', 'min:1', 'max:100'],
            'methode_allocation' => ['required', $enum(MethodeAllocation::class)],
            'seuil_garanti_petits' => ['nullable', 'required_if:methode_allocation,PRIORITE_PETITS', 'integer', 'min:1'],
            'plafond_concentration_pct' => ['nullable', 'integer', 'min:1', 'max:100'],

            // Tranche non atteinte
            'seuil_reussite_montant' => ['nullable', 'integer', 'min:0', 'lte:objectif_montant'],
            'regle_si_echec' => ['required', $enum(RegleSiEchec::class)],
            'jours_prolongation' => ['nullable', 'required_if:regle_si_echec,PROLONGATION', 'integer', 'min:1', 'max:365'],
            'delai_remboursement_jours' => ['required', 'integer', 'min:1', 'max:365'],

            // Frais
            'frais_paiement_supportes_par' => ['required', $enum(PayeurFrais::class)],
            'frais_souscription_type' => ['required', $enum(TypeFraisSouscription::class)],
            'frais_souscription_valeur' => ['nullable', 'required_unless:frais_souscription_type,AUCUN', 'integer', 'min:0',
                Rule::when($this->input('frais_souscription_type') === TypeFraisSouscription::Pourcentage->value, ['max:10000'])],

            // Paiement & conformité
            'canaux_actifs' => ['required', 'array', 'min:1'],
            'canaux_actifs.*' => [Rule::in(MoyenPaiement::proposables()->pluck('code')->all())],
            'delai_paiement_heures' => ['required', 'integer', 'min:1', 'max:720'],
            'seuil_kyc_renforce' => ['nullable', 'integer', 'min:0'],
            'seuil_revue_conformite' => ['nullable', 'integer', 'min:0'],
        ];
    }

    /**
     * Valeurs prêtes à enregistrer (champs inutiles selon les choix remis à null).
     *
     * @return array<string, mixed>
     */
    public function parametres(): array
    {
        $v = $this->validated();

        if ($v['regle_arret'] !== RegleArret::DepassementAutorise->value) {
            $v['pourcentage_depassement_max'] = null;
        }
        if ($v['methode_allocation'] !== MethodeAllocation::PrioritePetits->value) {
            $v['seuil_garanti_petits'] = null;
        }
        if ($v['regle_si_echec'] !== RegleSiEchec::Prolongation->value) {
            $v['jours_prolongation'] = null;
        }
        if ($v['frais_souscription_type'] === TypeFraisSouscription::Aucun->value) {
            $v['frais_souscription_valeur'] = 0;
        }

        $v['publics_autorises'] = array_values(array_unique($v['publics_autorises']));
        $v['canaux_actifs'] = array_values(array_unique($v['canaux_actifs']));

        return $v;
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return __('admin.tranches.champs');
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'date_cloture.after' => __('admin.tranches.erreurs.cloture_apres_ouverture'),
            'objectif_montant.gte' => __('admin.tranches.erreurs.objectif'),
            'plafond_actions_par_personne.gte' => __('admin.tranches.erreurs.plafond'),
            'seuil_reussite_montant.lte' => __('admin.tranches.erreurs.seuil_reussite'),
            '*.required_if' => __('admin.tranches.erreurs.requis_si'),
            'frais_souscription_valeur.required_unless' => __('admin.tranches.erreurs.requis_si'),
            'publics_autorises.required' => __('admin.tranches.erreurs.au_moins_un'),
            'canaux_actifs.required' => __('admin.tranches.erreurs.au_moins_un'),
        ];
    }
}
