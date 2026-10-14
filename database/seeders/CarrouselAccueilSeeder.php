<?php

namespace Database\Seeders;

use App\Services\Plateforme\ParametresPlateforme;
use App\Support\Diapositives;
use Illuminate\Database\Seeder;

/**
 * Contenu de départ du carrousel d'accueil, pour la recette uniquement : jamais en production, où les diapositives
 * sont saisies par l'ADMIN (Paramètres > Site public > Carrousel). Ne remplace jamais un carrousel déjà saisi.
 */
class CarrouselAccueilSeeder extends Seeder
{
    /** [texte alternatif, titre, sous-titre, libellé du bouton, lien du bouton]. */
    private const DIAPOSITIVES = [
        ['Allées du marché Raaga', 'Votre boutique au marché Raaga', 'Candidatez en ligne ou dans un guichet, payez votre caution, et participez au tirage au sort.', 'Je candidate', '/inscription'],
        ['Allées du marché Raaga', 'Un tirage au sort public', 'Chaque candidature complète et cautionnée participe au tirage, sans passe-droit.', 'Voir les boutiques', '/boutiques'],
        ['Allées du marché Raaga', 'Payez uniquement par les canaux officiels', 'Raaga ne vous demandera jamais de payer à une personne.', 'Questions fréquentes', '/faq'],
    ];

    public function run(): void
    {
        $parametres = app(ParametresPlateforme::class);
        if (app()->isProduction() || $parametres->get('site_public.carrousel', []) !== []) {
            return;
        }

        $diapositives = [];
        foreach (self::DIAPOSITIVES as $i => [$alt, $titre, $sousTitre, $libelle, $lien]) {
            $diapositives[] = [
                'image' => Diapositives::deposer(public_path('images/accueil/banniere-1920.webp')),
                'alt' => $alt, 'titre' => $titre, 'sousTitre' => $sousTitre, 'boutonLibelle' => $libelle, 'boutonLien' => $lien,
                'active' => true, 'debut' => null, 'fin' => null, 'ordre' => $i + 1,
            ];
        }
        $parametres->enregistrer('site_public', [...$parametres->section('site_public'), 'carrousel' => $diapositives], null);
    }
}
