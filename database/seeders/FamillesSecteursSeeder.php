<?php

namespace Database\Seeders;

use App\Models\FamilleSecteur;
use Illuminate\Database\Seeder;

/**
 * Familles de secteurs d'activité (03/11/2026). Lançable seul, y compris en production. Idempotent : il crée seulement
 * les codes absents et ne modifie jamais une famille existante.
 */
class FamillesSecteursSeeder extends Seeder
{
    /** code => libellé, dans l'ordre d'affichage. */
    public const FAMILLES = [
        'ALIMENTATION' => 'Alimentation',
        'HABILLEMENT_BEAUTE' => 'Habillement et beauté',
        'MAISON_EQUIPEMENT' => 'Maison et équipement',
        'AUTRES_COMMERCES' => 'Autres commerces',
        'SERVICES' => 'Services',
    ];

    public function run(): void
    {
        $ordre = 0;
        foreach (self::FAMILLES as $code => $libelle) {
            FamilleSecteur::query()->firstOrCreate(['code' => $code], ['libelle' => $libelle, 'ordre' => ++$ordre, 'actif' => true]);
        }
    }
}
