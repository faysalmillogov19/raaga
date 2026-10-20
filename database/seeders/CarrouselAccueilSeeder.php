<?php

namespace Database\Seeders;

use App\Services\Plateforme\ParametresPlateforme;
use App\Support\Diapositives;
use Illuminate\Database\Seeder;

/**
 * Contenu par défaut de l'ancien carrousel d'accueil : les quatre photos retenues (database/seeders/images/carrousel),
 * avec leurs textes et leur cadrage. Le carrousel est retiré de l'accueil (20/10/2026) : ce seeder n'est plus appelé par
 * DatabaseSeeder ; fichier conservé. Jamais en production. Ne remplace jamais un carrousel déjà saisi.
 */
class CarrouselAccueilSeeder extends Seeder
{
    /** Diapositives dans l'ordre d'affichage : fichier, textes, bouton, cadrage et position du texte. */
    private const DIAPOSITIVES = [
        [
            'fichier' => '1-officier.webp',
            'alt' => 'Un officier en béret rouge, assis à une table de conférence, montre un document',
            'titre' => 'Au premier arrivé, premier servi',
            'sousTitre' => 'Chacun choisit sa boutique sur le plan, sans priorité ni passe-droit.',
            'boutonLibelle' => 'Voir les boutiques', 'boutonLien' => '/boutiques',
            'cadrageX' => 47, 'cadrageY' => 0, 'texte' => 'panneau',
        ],
        [
            'fichier' => '2-galerie-marchande.webp',
            'alt' => 'Allée d’une galerie marchande couverte, rayons de boissons et de produits',
            'titre' => 'Votre boutique au marché Raaga',
            'sousTitre' => 'Réservez en ligne votre boutique sur le plan, puis payez la caution dans le délai.',
            'boutonLibelle' => 'Je réserve', 'boutonLien' => '/inscription',
            'cadrageX' => 50, 'cadrageY' => 50, 'texte' => 'gauche',
        ],
        [
            'fichier' => '3-commercantes.webp',
            'alt' => 'Deux commerçantes souriantes règlent un achat avec un téléphone et un terminal de paiement',
            'titre' => 'Payez uniquement par les canaux officiels',
            'sousTitre' => 'Raaga ne vous demandera jamais de payer à une personne.',
            'boutonLibelle' => 'Questions fréquentes', 'boutonLien' => '/faq',
            'cadrageX' => 50, 'cadrageY' => 50, 'texte' => 'gauche',
        ],
        [
            'fichier' => '4-marche-couvert.webp',
            'alt' => 'Vue d’architecte d’un marché couvert ouvert sur une esplanade, étals de fruits et passants',
            'titre' => 'Un marché couvert et organisé',
            'sousTitre' => 'Des espaces de vente abrités, adaptés à chaque activité.',
            'boutonLibelle' => 'Voir les boutiques', 'boutonLien' => '/boutiques',
            'cadrageX' => 50, 'cadrageY' => 50, 'texte' => 'gauche',
        ],
    ];

    public function run(): void
    {
        $parametres = app(ParametresPlateforme::class);
        if (app()->isProduction() || $parametres->get('site_public.carrousel', []) !== []) {
            return;
        }

        $diapositives = [];
        foreach (self::DIAPOSITIVES as $i => $d) {
            $diapositives[] = [
                'image' => Diapositives::deposer(database_path('seeders/images/carrousel/'.$d['fichier'])),
                ...array_diff_key($d, ['fichier' => true]),
                'active' => true, 'debut' => null, 'fin' => null, 'ordre' => $i + 1,
            ];
        }
        $parametres->enregistrer('site_public', [...$parametres->section('site_public'), 'carrousel' => $diapositives], null);
    }
}
