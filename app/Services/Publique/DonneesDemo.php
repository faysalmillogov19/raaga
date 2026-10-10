<?php

namespace App\Services\Publique;

use Carbon\CarbonImmutable;

/**
 * Données fictives des pages publiques, utilisées uniquement si RAAGA_DEMO=true
 * et qu'aucune tranche n'est ouverte.
 */
final class DonneesDemo
{
    public static function collecte(): EtatCollecte
    {
        return new EtatCollecte(
            nomTranche: 'Tranche 1 – 2026',
            valeurUnitaire: 10_000,
            plafondActionsParPersonne: null,
            objectifMontant: 3_000_000_000,
            dateOuverture: CarbonImmutable::parse('2026-10-01 08:00:00', 'Africa/Ouagadougou'),
            dateCloture: CarbonImmutable::parse('2026-12-31 23:59:59', 'Africa/Ouagadougou'),
            montantSouscrit: 1_284_650_000,
            nbSouscripteurs: 18_742,
            nbActions: 128_465,
            demo: true,
        );
    }

    public static function statistiques(): StatistiquesTransparence
    {
        return new StatistiquesTransparence(
            montantMedian: 120_000,
            montantParCanal: [
                'ORANGE_MONEY' => 612_400_000,
                'MOOV_MONEY' => 298_150_000,
                'VIREMENT' => 241_600_000,
                'ESPECES' => 132_500_000,
            ],
            souscripteursParTrancheMontant: [
                '≤ 100 000' => 11_230,
                '100 000 – 1 000 000' => 6_480,
                '1 000 000 – 10 000 000' => 960,
                '> 10 000 000' => 72,
            ],
        );
    }

    /**
     * Avancement du chantier : statut « fait », « en_cours » ou « a_venir ».
     *
     * @return list<array{titre: string, periode: string, statut: string}>
     */
    public static function chantier(): array
    {
        return [
            ['titre' => 'Études et permis de construire', 'periode' => '2025', 'statut' => 'fait'],
            ['titre' => 'Terrassement et fondations', 'periode' => '1er semestre 2026', 'statut' => 'fait'],
            ['titre' => 'Gros œuvre du bâtiment principal', 'periode' => '2e semestre 2026', 'statut' => 'en_cours'],
            ['titre' => 'Second œuvre et aménagement des boutiques', 'periode' => '2027', 'statut' => 'a_venir'],
            ['titre' => 'Ouverture du marché', 'periode' => 'Fin 2027', 'statut' => 'a_venir'],
        ];
    }

    /**
     * @return list<array{slug: string, etiquette: string, date: CarbonImmutable, titre: string, resume: string, contenu: list<string>}>
     */
    public static function actualites(): array
    {
        return [
            [
                'slug' => 'ouverture-tranche-1',
                'etiquette' => 'Souscription',
                'date' => CarbonImmutable::parse('2026-10-01'),
                'titre' => 'Ouverture de la tranche 1 de la participation populaire',
                'resume' => 'Les citoyens peuvent désormais souscrire aux actions B du marché Raaga, en ligne ou dans un guichet agréé.',
                'contenu' => [
                    'La tranche 1 de la participation populaire est ouverte. Chaque citoyen burkinabè peut souscrire aux actions B de Raaga SA, en ligne depuis son téléphone ou dans un guichet agréé.',
                    'Avant de souscrire, lisez le règlement de la tranche : il précise le prix de l’action, les dates, les frais et la règle de répartition en cas de forte demande.',
                    'Rappel : l’achat d’actions ne donne aucun droit à une boutique, et le rendement n’est pas garanti.',
                ],
            ],
            [
                'slug' => 'avancement-travaux-septembre-2026',
                'etiquette' => 'Chantier',
                'date' => CarbonImmutable::parse('2026-09-15'),
                'titre' => 'Point d’avancement des travaux du marché',
                'resume' => 'Les fondations du bâtiment principal sont achevées. Le prochain rapport d’avancement sera publié en décembre.',
                'contenu' => [
                    'Les fondations du bâtiment principal sont achevées. Les travaux de gros œuvre ont commencé.',
                    'Le prochain rapport d’avancement sera publié en décembre sur la page Transparence.',
                ],
            ],
            [
                'slug' => 'publication-note-information',
                'etiquette' => 'Information',
                'date' => CarbonImmutable::parse('2026-09-02'),
                'titre' => 'Publication de la note d’information',
                'resume' => 'La note d’information décrit le projet, les risques et les règles de la souscription. Lisez-la avant de souscrire.',
                'contenu' => [
                    'La note d’information de la participation populaire est publiée. Elle décrit le projet Raaga, la société Raaga SA, les risques et les règles de la souscription.',
                    'Elle est disponible dans la rubrique « Comprendre l’offre ».',
                ],
            ],
        ];
    }
}
