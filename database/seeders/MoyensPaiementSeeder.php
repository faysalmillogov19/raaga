<?php

namespace Database\Seeders;

use App\Enums\FamilleMoyenPaiement;
use App\Enums\StatutTypeBoutique;
use App\Enums\UsageMoyenPaiement;
use App\Models\MoyenPaiement;
use Illuminate\Database\Seeder;

/**
 * Moyens de paiement de départ : confirmés d'office, marqués « par défaut », version 1 au nom du système, sans
 * décision de validation ; créés s'ils manquent, jamais modifiés s'ils existent. Autorisés pour les deux usages
 * (actions, caution), plafonds vides : le RÉGULATEUR les saisit. CORIS_MONEY et WAVE sont créés inactifs.
 */
class MoyensPaiementSeeder extends Seeder
{
    /** code => [libellé, famille, ordre, actif] */
    public const MOYENS = [
        'FASO_ARZEKA' => ['Faso Arzeka', FamilleMoyenPaiement::PlateformePublique, 1, true],
        'ORANGE_MONEY' => ['Orange Money', FamilleMoyenPaiement::MobileMoney, 2, true],
        'MOOV_MONEY' => ['Moov Africa Money', FamilleMoyenPaiement::MobileMoney, 3, true],
        'TELECEL_MONEY' => ['Telecel Money', FamilleMoyenPaiement::MobileMoney, 4, true],
        'VIREMENT' => ['Virement bancaire', FamilleMoyenPaiement::Banque, 5, true],
        'VERSEMENT' => ['Versement bancaire', FamilleMoyenPaiement::Banque, 6, true],
        'CARTE_BANCAIRE' => ['Carte bancaire', FamilleMoyenPaiement::CarteBancaire, 7, true],
        'ESPECES' => ['Espèces au guichet', FamilleMoyenPaiement::Guichet, 8, true],
        'CORIS_MONEY' => ['Coris Money', FamilleMoyenPaiement::MobileMoney, 9, false],
        'WAVE' => ['Wave', FamilleMoyenPaiement::MobileMoney, 10, false],
    ];

    public function run(): void
    {
        $usages = collect(UsageMoyenPaiement::cases())->mapWithKeys(fn (UsageMoyenPaiement $u) => [$u->value => ['autorise' => true, 'min' => null, 'max' => null]])->all();
        foreach (self::MOYENS as $code => [$libelle, $famille, $ordre, $actif]) {
            $moyen = MoyenPaiement::query()->firstOrCreate(['code' => $code], [
                'libelle' => $libelle, 'famille' => $famille, 'usages' => $usages, 'ordre' => $ordre, 'actif' => $actif,
                'par_defaut' => true, 'statut' => StatutTypeBoutique::Valide,
            ]);
            if ($moyen->wasRecentlyCreated) {
                $moyen->forceFill(['valide_le' => now()])->save();
                $moyen->creerVersion(null, __('admin.types.motifs.defaut'));
            }
        }
    }
}
