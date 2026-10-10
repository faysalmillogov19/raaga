<?php

namespace App\Support;

use App\Enums\Droit;
use App\Models\User;

/**
 * Menu de la barre latérale du back-office. Chaque entrée est protégée par un droit :
 * le même droit filtre le menu et protège la route (middleware « can:… »).
 */
final class MenuBackoffice
{
    /**
     * « etape » : étape de livraison (back-office métier), ou du module d'administration quand « module » = admin.
     * « masque_si » : entrée retirée du menu pour qui détient ce droit (le SUPER_ADMIN consulte les paramètres
     * depuis l'entrée « Paramètres (lecture) » ; la route reste accessible).
     *
     * @return array<string, list<array{cle: string, route: string, droit: Droit, icone: string, etape: int, module?: string, masque_si?: Droit}>>
     */
    public static function definition(): array
    {
        $parametres = Droit::ParametresVoir;

        return [
            'pilotage' => [
                ['cle' => 'tableau', 'route' => 'admin.tableau', 'droit' => Droit::TableauDeBordVoir, 'icone' => 'tableau', 'etape' => 1],
                ['cle' => 'tranches', 'route' => 'admin.tranches', 'droit' => Droit::TranchesVoir, 'icone' => 'tranches', 'etape' => 3, 'masque_si' => $parametres],
                ['cle' => 'dividendes', 'route' => 'admin.dividendes', 'droit' => Droit::DividendesVoir, 'icone' => 'dividendes', 'etape' => 3, 'masque_si' => $parametres],
                ['cle' => 'simulations', 'route' => 'admin.simulations', 'droit' => Droit::SimulationsVoir, 'icone' => 'simulations', 'etape' => 4, 'masque_si' => $parametres],
            ],
            'support' => [
                ['cle' => 'assistance', 'route' => 'admin.assistance', 'droit' => Droit::AssistanceVoir, 'icone' => 'assistance', 'etape' => 1, 'module' => 'admin'],
                ['cle' => 'demandes', 'route' => 'admin.demandes', 'droit' => Droit::DemandesTraiter, 'icone' => 'demandes', 'etape' => 2, 'module' => 'admin'],
                ['cle' => 'contenus', 'route' => 'admin.contenus', 'droit' => Droit::ContenusGerer, 'icone' => 'contenus', 'etape' => 3, 'module' => 'admin'],
            ],
            'actions_a' => [
                ['cle' => 'institutions', 'route' => 'admin.institutions', 'droit' => Droit::InstitutionsGerer, 'icone' => 'institutions', 'etape' => 0],
                ['cle' => 'dossiers_kyb', 'route' => 'admin.kyb', 'droit' => Droit::KybVoir, 'icone' => 'kyc', 'etape' => 0],
                ['cle' => 'conventions', 'route' => 'admin.conventions', 'droit' => Droit::ConventionsVoir, 'icone' => 'conventions', 'etape' => 0],
                ['cle' => 'appels_fonds', 'route' => 'admin.appels-fonds', 'droit' => Droit::AppelsFondsVoir, 'icone' => 'souscriptions', 'etape' => 0],
                ['cle' => 'registre_a', 'route' => 'admin.registre-a', 'droit' => Droit::RegistreAVoir, 'icone' => 'registre', 'etape' => 0],
            ],
            'operations' => [
                ['cle' => 'kyc', 'route' => 'admin.kyc', 'droit' => Droit::KycVoir, 'icone' => 'kyc', 'etape' => 5],
                ['cle' => 'conformite', 'route' => 'admin.conformite', 'droit' => Droit::ConformiteVoir, 'icone' => 'conformite', 'etape' => 5],
                ['cle' => 'souscriptions', 'route' => 'admin.souscriptions', 'droit' => Droit::SouscriptionsVoir, 'icone' => 'souscriptions', 'etape' => 5],
                ['cle' => 'remboursements', 'route' => 'admin.remboursements', 'droit' => Droit::RemboursementsVoir, 'icone' => 'remboursements', 'etape' => 5],
            ],
            'controle' => [
                ['cle' => 'registre', 'route' => 'admin.registre', 'droit' => Droit::RegistreVoir, 'icone' => 'registre', 'etape' => 6],
                ['cle' => 'audit', 'route' => 'admin.audit', 'droit' => Droit::AuditVoir, 'icone' => 'audit', 'etape' => 6],
                ['cle' => 'regulation', 'route' => 'admin.regulation', 'droit' => Droit::RapportsGenerer, 'icone' => 'regulation', 'etape' => 6],
                ['cle' => 'observations', 'route' => 'admin.observations', 'droit' => Droit::ObservationsVoir, 'icone' => 'observations', 'etape' => 6],
            ],
            'administration' => [
                ['cle' => 'approbations', 'route' => 'admin.approbations', 'droit' => Droit::ApprobationsTraiter, 'icone' => 'approbations', 'etape' => 5, 'module' => 'admin'],
                ['cle' => 'utilisateurs', 'route' => 'admin.utilisateurs', 'droit' => Droit::UtilisateursGerer, 'icone' => 'utilisateurs', 'etape' => 2],
                ['cle' => 'plateforme', 'route' => 'admin.plateforme', 'droit' => Droit::PlateformeVoir, 'icone' => 'plateforme', 'etape' => 0, 'module' => 'admin'],
                ['cle' => 'securite', 'route' => 'admin.securite', 'droit' => Droit::SecuriteGerer, 'icone' => 'securite', 'etape' => 6, 'module' => 'admin'],
                ['cle' => 'connexions', 'route' => 'admin.connexions', 'droit' => Droit::ConnexionsVoir, 'icone' => 'connexions', 'etape' => 1],
                ['cle' => 'referentiels', 'route' => 'admin.referentiels', 'droit' => Droit::ReferentielsVoir, 'icone' => 'referentiels', 'etape' => 2, 'masque_si' => $parametres],
                ['cle' => 'parametres', 'route' => 'admin.parametres', 'droit' => Droit::ParametresVoir, 'icone' => 'parametres', 'etape' => 0, 'module' => 'admin'],
            ],
        ];
    }

    /**
     * Entrées visibles pour l'utilisateur, groupées ; les groupes vides sont retirés.
     *
     * @return array<string, list<array{cle: string, route: string, droit: Droit, icone: string, etape: int}>>
     */
    public static function pour(User $user): array
    {
        return array_filter(array_map(
            fn (array $entrees) => array_values(array_filter($entrees, fn (array $e) => $user->can($e['droit']->value)
                && ! (isset($e['masque_si']) && $user->can($e['masque_si']->value)))),
            self::definition(),
        ));
    }

    /**
     * @return array{cle: string, route: string, droit: Droit, icone: string, etape: int}|null
     */
    public static function entree(string $cle): ?array
    {
        foreach (self::definition() as $entrees) {
            foreach ($entrees as $entree) {
                if ($entree['cle'] === $cle) {
                    return $entree;
                }
            }
        }

        return null;
    }
}
