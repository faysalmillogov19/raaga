<?php

namespace Database\Seeders;

use App\Models\FamilleSecteur;
use App\Models\SecteurActivite;
use Illuminate\Database\Seeder;

/**
 * Secteurs d'activité des commerçants (03/11/2026), chacun rattaché à sa famille (créée au besoin par
 * FamillesSecteursSeeder). Lançable seul, y compris en production. Idempotent : il crée seulement les codes absents et
 * ne modifie jamais un secteur existant.
 */
class SecteursActiviteSeeder extends Seeder
{
    /** code de la famille => [code du secteur => libellé], dans l'ordre d'affichage. */
    public const SECTEURS = [
        'ALIMENTATION' => [
            'CEREALES' => 'Céréales et légumineuses (mil, maïs, riz, niébé)',
            'FRUITS_LEGUMES' => 'Fruits et légumes',
            'VIANDE' => 'Boucherie et volaille',
            'POISSON' => 'Poisson frais, fumé ou séché',
            'LAIT_OEUFS' => 'Produits laitiers et œufs',
            'EPICERIE' => 'Épicerie et alimentation générale',
            'TERROIR' => 'Condiments, épices et produits du terroir (soumbala, karité, miel)',
            'BOISSONS' => 'Boissons',
            'RESTAURATION' => 'Restauration et petite restauration',
        ],
        'HABILLEMENT_BEAUTE' => [
            'TISSUS' => 'Tissus et pagnes (Faso Dan Fani, wax)',
            'PRET_A_PORTER' => 'Prêt-à-porter et friperie',
            'COUTURE' => 'Couture et confection',
            'CHAUSSURES' => 'Chaussures, sacs et maroquinerie',
            'BEAUTE' => 'Cosmétiques, beauté et coiffure',
            'BIJOUX' => 'Bijouterie et accessoires',
        ],
        'MAISON_EQUIPEMENT' => [
            'QUINCAILLERIE' => 'Quincaillerie et matériaux de construction',
            'ELECTRONIQUE' => 'Électronique, téléphonie et accessoires',
            'ELECTROMENAGER' => 'Électroménager',
            'MENAGE' => 'Articles ménagers, ustensiles et plastiques',
            'MEUBLES' => 'Meubles et décoration',
        ],
        'AUTRES_COMMERCES' => [
            'PAPETERIE' => 'Papeterie, librairie et fournitures scolaires',
            'ARTISANAT' => 'Artisanat d’art (bronze, cuir, vannerie)',
            'PIECES_DETACHEES' => 'Pièces détachées (deux-roues surtout)',
            'INTRANTS' => 'Intrants agricoles, aliments et produits d’élevage',
        ],
        'SERVICES' => [
            'SERVICES' => 'Services (transfert d’argent, réparation, photocopie)',
            SecteurActivite::AUTRE => 'Autre activité (à préciser)',
        ],
    ];

    public function run(): void
    {
        $this->callSilent(FamillesSecteursSeeder::class);
        $familles = FamilleSecteur::query()->pluck('id', 'code');

        foreach (self::SECTEURS as $famille => $secteurs) {
            $ordre = 0;
            foreach ($secteurs as $code => $libelle) {
                SecteurActivite::query()->firstOrCreate(['code' => $code], [
                    'famille_secteur_id' => $familles[$famille], 'libelle' => $libelle, 'ordre' => ++$ordre, 'actif' => true,
                ]);
            }
        }
    }
}
