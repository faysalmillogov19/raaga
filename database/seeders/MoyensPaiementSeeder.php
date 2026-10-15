<?php

namespace Database\Seeders;

use App\Enums\StatutTypeBoutique;
use App\Enums\TypeMoyenPaiement;
use App\Models\MoyenPaiement;
use Illuminate\Database\Seeder;

/**
 * Moyens de paiement par défaut : confirmés d'office, marqués « par défaut », version 1 au nom du système ; créés
 * s'ils manquent, jamais modifiés s'ils existent. Aucun passage par le circuit de validation.
 */
class MoyensPaiementSeeder extends Seeder
{
    /** code => [libellé, type, ordre] */
    public const MOYENS = [
        'ORANGE_MONEY' => ['Orange Money', TypeMoyenPaiement::MobileMoney, 1],
        'MOOV_MONEY' => ['Moov Africa Money', TypeMoyenPaiement::MobileMoney, 2],
        'TELECEL_MONEY' => ['Telecel Money', TypeMoyenPaiement::MobileMoney, 3],
        'FASO_ARZEKA' => ['Faso Arzeka', TypeMoyenPaiement::Passerelle, 4],
        'VIREMENT' => ['Virement bancaire', TypeMoyenPaiement::Virement, 5],
        'ESPECES' => ['Espèces au guichet', TypeMoyenPaiement::Especes, 6],
    ];

    public function run(): void
    {
        foreach (self::MOYENS as $code => [$libelle, $type, $ordre]) {
            $moyen = MoyenPaiement::query()->firstOrCreate(['code' => $code], [
                'libelle' => $libelle, 'type' => $type, 'ordre' => $ordre, 'actif' => true, 'par_defaut' => true, 'statut' => StatutTypeBoutique::Valide,
            ]);
            if ($moyen->wasRecentlyCreated) {
                $moyen->forceFill(['valide_le' => now()])->save();
                $moyen->creerVersion(null, __('admin.types.motifs.defaut'));
            }
        }
    }
}
