<?php

namespace App\Support;

use App\Enums\Droit;
use App\Enums\StatutBoutique;
use App\Enums\StatutGrilleTarifaire;
use App\Enums\StatutSite;
use App\Enums\StatutTypeBoutique;
use App\Models\Boutique;
use App\Models\CapaciteSite;
use App\Models\GrilleTarifaire;
use App\Models\Niveau;
use App\Models\Site;
use App\Models\TypeBoutique;
use App\Models\Unite;
use App\Models\User;
use App\Models\ZoneSite;

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
                ['cle' => 'boutiques', 'route' => 'admin.boutiques', 'droit' => Droit::BoutiquesVoir, 'icone' => 'boutiques', 'etape' => 0, 'masque_si' => $parametres],
                ['cle' => 'dividendes', 'route' => 'admin.dividendes', 'droit' => Droit::DividendesVoir, 'icone' => 'dividendes', 'etape' => 3, 'masque_si' => $parametres],
                ['cle' => 'simulations', 'route' => 'admin.simulations', 'droit' => Droit::SimulationsVoir, 'icone' => 'simulations', 'etape' => 4, 'masque_si' => $parametres],
            ],
            // Référencement des sites et des boutiques (GESTIONNAIRE DES SITES ET BOUTIQUES, RÉGULATEUR pour la grille), puis le
            // menu dépliant « Référentiels » (entrées marquées « sous_menu ») : types, niveaux, zones, unités.
            'referencement' => [
                ['cle' => 'sites', 'route' => 'admin.sites', 'droit' => Droit::SitesVoir, 'icone' => 'lieu', 'etape' => 0,
                    'exclure' => ['admin.sites.types', 'admin.sites.types.*', 'admin.sites.excel.*', 'admin.sites.boutiques-excel.*']],
                ['cle' => 'boutiques_referentiel', 'route' => 'admin.referencement.boutiques', 'droit' => Droit::SitesVoir, 'icone' => 'boutiques', 'etape' => 0],
                ['cle' => 'capacites', 'route' => 'admin.referencement.capacites', 'droit' => Droit::SitesVoir, 'icone' => 'simulations', 'etape' => 0],
                ['cle' => 'grille', 'route' => 'admin.boutiques.grilles', 'droit' => Droit::GrilleVoir, 'icone' => 'dividendes', 'etape' => 0],
                ['cle' => 'imports', 'route' => 'admin.referencement.imports', 'droit' => Droit::ImportsVoir, 'icone' => 'registre', 'etape' => 0,
                    'inclure' => ['admin.sites.excel.*', 'admin.sites.boutiques-excel.*']],
                ['cle' => 'types', 'route' => 'admin.sites.types', 'droit' => Droit::SitesVoir, 'icone' => 'referentiels', 'etape' => 0, 'sous_menu' => 'referentiels'],
                ['cle' => 'niveaux', 'route' => 'admin.referencement.niveaux', 'droit' => Droit::SitesVoir, 'icone' => 'tranches', 'etape' => 0,
                    'liste' => 'niveaux', 'sous_menu' => 'referentiels'],
                ['cle' => 'zones', 'route' => 'admin.referencement.zones', 'droit' => Droit::SitesVoir, 'icone' => 'lieu', 'etape' => 0,
                    'exclure' => ['admin.referencement.zones.*'], 'liste' => 'zones', 'sous_menu' => 'referentiels'],
                ['cle' => 'unites', 'route' => 'admin.referencement.unites', 'droit' => Droit::UnitesVoir, 'icone' => 'tableau', 'etape' => 0,
                    'liste' => 'unites', 'sous_menu' => 'referentiels'],
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
                ['cle' => 'phase', 'route' => 'admin.phase', 'droit' => Droit::PhaseVoir, 'icone' => 'approbations', 'etape' => 0],
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
     * Entrées d'un groupe regroupées pour l'affichage : une entrée seule, ou un menu dépliant (« sous_menu ») qui
     * réunit ses sous-entrées à la place de la première d'entre elles.
     *
     * @param  list<array<string, mixed>>  $entrees
     * @return list<array{type: string, entree?: array, cle?: string, entrees?: list<array>}>
     */
    public static function regrouper(array $entrees): array
    {
        $elements = [];
        $positions = [];
        foreach ($entrees as $entree) {
            $menu = $entree['sous_menu'] ?? null;
            if ($menu === null) {
                $elements[] = ['type' => 'entree', 'entree' => $entree];

                continue;
            }
            if (! isset($positions[$menu])) {
                $positions[$menu] = count($elements);
                $elements[] = ['type' => 'menu', 'cle' => $menu, 'entrees' => []];
            }
            $elements[$positions[$menu]]['entrees'][] = $entree;
        }

        return $elements;
    }

    /** Menu dépliant ouvert : page affichée dans le menu, sinon dernier état mémorisé pour l'utilisateur. */
    public static function menuOuvert(User $user, string $menu, array $entrees): bool
    {
        if (collect($entrees)->contains(fn (array $e) => self::estActive($e))) {
            return true;
        }

        return (bool) (($user->menus_backoffice ?? [])[$menu] ?? false);
    }

    /** L'entrée est-elle celle de la page affichée ? (route et sous-routes, moins les exclusions, plus les inclusions) */
    public static function estActive(array $entree): bool
    {
        if (isset($entree['liste']) && request()->routeIs('admin.referencement.zones.*')) {
            return request()->route('liste') === $entree['liste'];
        }

        return (request()->routeIs($entree['route'], $entree['route'].'.*') && ! request()->routeIs(...($entree['exclure'] ?? ['-'])))
            || request()->routeIs(...($entree['inclure'] ?? ['-']));
    }

    /**
     * Nombre d'éléments à traiter par le profil connecté, par entrée du menu (badge) ; absent : pas de badge.
     *
     * @return array<string, int>
     */
    public static function badges(User $user): array
    {
        $valide = $user->can(Droit::SitesConfirmer->value);
        $gere = $user->can(Droit::SitesGerer->value);

        return array_filter([
            // VALIDATEUR : sites soumis ; GESTIONNAIRE : sites rejetés à reprendre.
            'sites' => match (true) {
                $valide => Site::query()->where('statut', StatutSite::Soumis)->count(),
                $gere => Site::query()->where('statut', StatutSite::Brouillon)->whereNotNull('motif_rejet')->count(),
                default => 0,
            },
            'types' => match (true) {
                $valide => TypeBoutique::query()->where(fn ($q) => $q->where('statut', StatutTypeBoutique::AValider)->orWhereNotNull('attribuable_demande'))->count(),
                $gere => TypeBoutique::query()->where('statut', StatutTypeBoutique::Rejete)->count(),
                default => 0,
            },
            // VALIDATEUR : niveaux et zones à valider ; GESTIONNAIRE : niveaux et zones rejetés.
            'niveaux' => match (true) {
                $valide => Niveau::query()->where('statut', StatutTypeBoutique::AValider)->count(),
                $gere => Niveau::query()->where('statut', StatutTypeBoutique::Rejete)->count(),
                default => 0,
            },
            'zones' => match (true) {
                $valide => ZoneSite::query()->where('statut', StatutTypeBoutique::AValider)->count(),
                $gere => ZoneSite::query()->where('statut', StatutTypeBoutique::Rejete)->count(),
                default => 0,
            },
            // VALIDATEUR : unités à valider ; RÉGULATEUR : unités rejetées à corriger.
            'unites' => match (true) {
                $user->can(Droit::UnitesConfirmer->value) => Unite::query()->where('statut', StatutTypeBoutique::AValider)->count(),
                $user->can(Droit::UnitesGerer->value) => Unite::query()->where('statut', StatutTypeBoutique::Rejete)->count(),
                default => 0,
            },
            // GESTIONNAIRE : boutiques sans niveau (à compléter avant soumission) et capacités incomplètes.
            'boutiques_referentiel' => $gere ? Boutique::query()->whereNull('niveau')->where('statut', '!=', StatutBoutique::Retiree)->count() : 0,
            'capacites' => $gere ? CapaciteSite::query()->whereNull('nombre_prevu')->whereNull('surface_totale')->count() : 0,
            // RÉGULATEUR : grilles en brouillon ; VALIDATEUR : grilles soumises.
            'grille' => match (true) {
                $user->can(Droit::BoutiquesConfirmer->value) => GrilleTarifaire::query()->where('statut', StatutGrilleTarifaire::Soumis)->count(),
                $user->can(Droit::BoutiquesSaisir->value) => GrilleTarifaire::query()->where('statut', StatutGrilleTarifaire::Brouillon)->count(),
                default => 0,
            },
        ]);
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
