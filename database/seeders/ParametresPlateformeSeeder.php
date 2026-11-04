<?php

namespace Database\Seeders;

use App\Services\Plateforme\ParametresPlateforme;
use App\Support\Plateforme\Catalogue;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Valeurs initiales des paramètres de la plateforme : celles qui étaient codées en dur dans les
 * pages avant la création du module. Les champs inconnus restent vides (non affichés).
 * Ne remplace jamais une section déjà enregistrée.
 */
class ParametresPlateformeSeeder extends Seeder
{
    public function run(): void
    {
        $valeurs = [
            'identite' => [
                'nomPlateforme' => 'Raaga · Participation populaire',
                'sousTitre' => 'Plateforme officielle de souscription aux actions populaires du marché Raaga',
                'mentionTutelle' => 'Placée sous la tutelle du Gouvernement du Burkina Faso.',
                'deviseNationale' => 'La Patrie ou la Mort, nous Vaincrons',
                'expediteurSms' => 'RAAGA',
            ],
            'contacts' => [
                'numeroVert' => '+22680000000',
            ],
            'mentions' => [
                'denominationSociale' => 'Raaga SA',
            ],
            'projet' => [
                'nombreBoutiques' => 856,
                'coutTotalProjet' => 16_245_000_000,
                'repartitionFinancement' => [
                    ['libelle' => 'Futurs occupants', 'montant' => 6_454_000_000, 'couleur' => 'vert', 'approximatif' => true, 'ordre' => 1],
                    ['libelle' => 'Participation populaire', 'montant' => 3_000_000_000, 'couleur' => 'jaune', 'approximatif' => false, 'ordre' => 2],
                    ['libelle' => 'CNSS, CARFO et autres', 'montant' => 6_791_000_000, 'couleur' => 'gris', 'approximatif' => true, 'ordre' => 3],
                ],
            ],
            'messages' => [
                'messageAntiFraude' => 'Raaga ne vous demandera jamais votre mot de passe par téléphone, SMS ou courriel.',
            ],
        ];

        foreach (array_keys(Catalogue::SECTIONS) as $section) {
            if (DB::table('parametres_plateforme')->where('section', $section)->exists()) {
                continue;
            }

            DB::table('parametres_plateforme')->insert([
                'section' => $section,
                'valeurs' => json_encode(array_replace(Catalogue::vide($section), $valeurs[$section] ?? []), JSON_UNESCAPED_UNICODE),
                'modifie_par' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        app(ParametresPlateforme::class)->viderCache();
    }
}
