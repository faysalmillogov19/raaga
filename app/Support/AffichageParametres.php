<?php

namespace App\Support;

use App\Enums\ClasseActions;
use App\Enums\FamilleMoyenPaiement;
use App\Enums\MethodeAllocation;
use App\Enums\ModeCalculDividende;
use App\Enums\ModeInscriptionA;
use App\Enums\ModeSaisie;
use App\Enums\PayeurFrais;
use App\Enums\PublicAutorise;
use App\Enums\RegleArret;
use App\Enums\RegleSiEchec;
use App\Enums\TraitementNonReclames;
use App\Enums\TypeFraisSouscription;
use App\Enums\UsageMoyenPaiement;
use App\Models\MoyenPaiement;
use Carbon\CarbonImmutable;

/**
 * Valeur lisible d'un paramètre (tranche ou distribution) pour les écrans de revue et le différentiel.
 */
final class AffichageParametres
{
    private const ENUMS = [
        'publics_autorises' => PublicAutorise::class,
        'mode_saisie' => ModeSaisie::class,
        'regle_arret' => RegleArret::class,
        'methode_allocation' => MethodeAllocation::class,
        'regle_si_echec' => RegleSiEchec::class,
        'frais_paiement_supportes_par' => PayeurFrais::class,
        'frais_souscription_type' => TypeFraisSouscription::class,
        'mode_calcul' => ModeCalculDividende::class,
        'traitement_non_reclames' => TraitementNonReclames::class,
        'mode_inscription' => ModeInscriptionA::class,
        'classe_actions' => ClasseActions::class,
        'famille' => FamilleMoyenPaiement::class,
    ];

    private const MONTANTS = [
        'valeur_unitaire', 'objectif_montant', 'seuil_reussite_montant', 'seuil_kyc_renforce', 'seuil_revue_conformite',
        'resultat_distribuable', 'montant_global', 'dividende_brut_par_action', 'seuil_minimal',
        'prix_unitaire', 'montant_engage',
    ];

    private const DATES_HEURES = ['date_ouverture', 'date_cloture'];

    private const DATES = ['ag_date', 'date_arrete', 'date_mise_en_paiement'];

    public static function valeur(string $champ, mixed $valeur): string
    {
        if ($valeur === null || $valeur === '' || $valeur === []) {
            return $champ === 'plafond_actions_par_personne' ? __('admin.tranches.sans_plafond') : '—';
        }

        if ($valeur instanceof \BackedEnum) {
            $valeur = $valeur->value;
        }

        // Pour un dividende, le « souscripteur » est l'actionnaire.
        if ($champ === 'frais_supportes_par') {
            return __('admin.dividendes.payeurs')[$valeur] ?? (string) $valeur;
        }

        // Échéancier d'une convention : « Libellé (jj/mm/aaaa) : montant ; … ».
        if ($champ === 'echeancier' && is_array($valeur)) {
            return implode(' ; ', array_map(fn (array $l) => $l['libelle'].' ('.CarbonImmutable::parse($l['echeance'])->format('d/m/Y').') : '
                .format_fcfa((int) $l['montant']), $valeur));
        }

        if ($champ === 'nb_actions') {
            return format_nombre((int) $valeur);
        }

        // Usages d'un moyen de paiement : « Souscription d’actions : jusqu’à 1 000 000 FCFA · Caution d’une boutique : non autorisé ».
        if ($champ === 'usages' && is_array($valeur)) {
            $p = __('admin.moyens_paiement');

            return collect(UsageMoyenPaiement::cases())->map(function (UsageMoyenPaiement $u) use ($valeur, $p) {
                $v = (array) ($valeur[$u->value] ?? []);
                $plafonds = ! ($v['autorise'] ?? false) ? $p['non_autorise'] : (collect([
                    isset($v['min']) ? __('admin.moyens_paiement.plafond_min', ['montant' => format_fcfa((int) $v['min'])]) : null,
                    isset($v['max']) ? __('admin.moyens_paiement.plafond_max', ['montant' => format_fcfa((int) $v['max'])]) : null,
                ])->filter()->implode(', ') ?: $p['sans_limite']);

                return __('admin.moyens_paiement.usage_resume', ['usage' => $u->label(), 'plafonds' => $plafonds]);
            })->implode(' · ');
        }

        // Moyens de paiement : libellés du référentiel (un code retiré reste affiché tel quel).
        if (in_array($champ, ['canaux_actifs', 'canaux_autorises', 'canal_defaut'], true)) {
            $libelle = fn ($v) => MoyenPaiement::libelleDe($v instanceof MoyenPaiement ? $v->code : (string) $v);

            return is_array($valeur) ? implode(', ', array_map($libelle, $valeur)) : $libelle($valeur);
        }

        if (isset(self::ENUMS[$champ])) {
            $enum = self::ENUMS[$champ];
            $libelle = fn ($v) => $enum::tryFrom((string) $v)?->label() ?? (string) $v;

            return is_array($valeur) ? implode(', ', array_map($libelle, $valeur)) : $libelle($valeur);
        }

        if (is_bool($valeur) || $champ === 'souscriptions_multiples') {
            return $valeur ? __('admin.tranches.oui') : __('admin.tranches.non');
        }

        if (in_array($champ, self::MONTANTS, true)) {
            return format_fcfa((int) $valeur);
        }

        if ($champ === 'taux_irvm_pb') {
            return format_nombre(((int) $valeur) / 100, 2).' %';
        }

        if (in_array($champ, self::DATES_HEURES, true)) {
            return CarbonImmutable::parse($valeur)->format('d/m/Y H:i');
        }

        if (in_array($champ, self::DATES, true)) {
            return CarbonImmutable::parse($valeur)->format('d/m/Y');
        }

        if ($champ === 'pv_empreinte') {
            return mb_substr((string) $valeur, 0, 16).'…';
        }

        return is_array($valeur) ? implode(', ', $valeur) : (string) $valeur;
    }
}
