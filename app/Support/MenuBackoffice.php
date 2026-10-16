<?php

namespace App\Support;

use App\Enums\Droit;
use App\Enums\Role;
use App\Enums\StatutBoutique;
use App\Enums\StatutGrilleTarifaire;
use App\Enums\StatutSite;
use App\Enums\StatutTypeBoutique;
use App\Models\Boutique;
use App\Models\CapaciteSite;
use App\Models\GrilleTarifaire;
use App\Models\ImportEnAttente;
use App\Models\MoyenPaiement;
use App\Models\Niveau;
use App\Models\Site;
use App\Models\TypeBoutique;
use App\Models\Unite;
use App\Models\User;
use App\Models\ZoneSite;
use App\Services\Validation\SoumissionGroupee;

/**
 * Menu de la barre latérale du back-office. Chaque entrée est protégée par un droit :
 * le même droit filtre le menu et protège la route (middleware « can:… »).
 */
final class MenuBackoffice
{
    /** Icône de chaque menu dépliant (barre latérale réduite en icônes). */
    public const ICONES_MENUS = ['referentiels' => 'referentiels', 'modalites' => 'dividendes', 'imports' => 'importer', 'a_valider' => 'taches'];

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
                // Menu dépliant « À valider » : vue d'ensemble, puis une entrée par rubrique (déclarées dans AValider::rubriques()).
                ['cle' => 'a_valider', 'route' => 'admin.a-valider', 'droit' => Droit::AValiderVoir, 'icone' => 'taches', 'etape' => 0,
                    'sous_menu' => 'a_valider', 'exclure' => ['admin.a-valider.rubrique'], 'compteur_zero' => true, 'libelle' => __('admin.a_valider.menus.vue')],
                ...array_map(fn (string $groupe, array $r) => ['cle' => 'a_valider_'.$groupe, 'route' => 'admin.a-valider.rubrique', 'parametres' => ['rubrique' => $r['slug']],
                    'droit' => $r['droits'][0], 'icone' => 'taches', 'etape' => 0, 'sous_menu' => 'a_valider', 'compteur_zero' => true,
                    'libelle' => __('admin.a_valider.menus.'.$groupe)], array_keys(AValider::rubriques()), AValider::rubriques()),
                // « Mes brouillons » (GESTIONNAIRE, RÉGULATEUR) : badge = brouillons et rejetés non soumis.
                ['cle' => 'brouillons', 'route' => 'admin.brouillons', 'droit' => Droit::BrouillonsVoir, 'icone' => 'taches', 'etape' => 0],
                ['cle' => 'tranches', 'route' => 'admin.tranches', 'droit' => Droit::TranchesVoir, 'icone' => 'tranches', 'etape' => 3, 'masque_si' => $parametres],
                // Menu dépliant « Modalités de paiement » (RÉGULATEUR ; SUPER_ADMIN en lecture) : moyens de paiement, puis délais
                // (délai de complément d'un paiement incomplet). Le VALIDATEUR décide depuis « À valider », sans cet onglet.
                ['cle' => 'moyens', 'route' => 'admin.referencement.moyens', 'droit' => Droit::ModalitesPaiementVoir, 'icone' => 'dividendes', 'etape' => 0,
                    'liste' => 'moyens', 'sous_menu' => 'modalites'],
                ['cle' => 'delais', 'route' => 'admin.parametres-paiements', 'droit' => Droit::ModalitesPaiementVoir, 'icone' => 'taches', 'etape' => 0, 'sous_menu' => 'modalites'],
                ['cle' => 'boutiques', 'route' => 'admin.boutiques', 'droit' => Droit::BoutiquesVoir, 'icone' => 'boutiques', 'etape' => 0, 'masque_si' => $parametres],
                ['cle' => 'dividendes', 'route' => 'admin.dividendes', 'droit' => Droit::DividendesVoir, 'icone' => 'dividendes', 'etape' => 3, 'masque_si' => $parametres],
                // RAAGA Invest : paramètres d'investissement, manifestations d'intérêt.
                ['cle' => 'investissement', 'route' => 'admin.investissement', 'droit' => Droit::TranchesVoir, 'icone' => 'tranches', 'etape' => 0, 'masque_si' => $parametres],
                ['cle' => 'interets', 'route' => 'admin.interets', 'droit' => Droit::InteretsVoir, 'icone' => 'tableau', 'etape' => 0],
                ['cle' => 'simulations', 'route' => 'admin.simulations', 'droit' => Droit::SimulationsVoir, 'icone' => 'simulations', 'etape' => 4, 'masque_si' => $parametres],
            ],
            // Référencement des sites et des boutiques (GESTIONNAIRE DES SITES ET BOUTIQUES, RÉGULATEUR pour la grille et les
            // unités), puis le menu dépliant « Référentiels » (GESTIONNAIRE ; SUPER_ADMIN en lecture) : types, niveaux, zones.
            'referencement' => [
                ['cle' => 'sites', 'route' => 'admin.sites', 'droit' => Droit::SitesVoir, 'icone' => 'lieu', 'etape' => 0,
                    'exclure' => ['admin.sites.types', 'admin.sites.types.*', 'admin.sites.excel.*', 'admin.sites.boutiques-excel.*']],
                ['cle' => 'boutiques_referentiel', 'route' => 'admin.referencement.boutiques', 'droit' => Droit::SitesVoir, 'icone' => 'boutiques', 'etape' => 0],
                ['cle' => 'capacites', 'route' => 'admin.referencement.capacites', 'droit' => Droit::SitesVoir, 'icone' => 'simulations', 'etape' => 0],
                ['cle' => 'grille', 'route' => 'admin.boutiques.grilles', 'droit' => Droit::GrilleVoir, 'icone' => 'dividendes', 'etape' => 0],
                // Unités de loyer (M2, UNITE, REDEVANCE) : près de la grille tarifaire, pour le RÉGULATEUR (SUPER_ADMIN en lecture).
                ['cle' => 'unites', 'route' => 'admin.referencement.unites', 'droit' => Droit::UnitesVoir, 'icone' => 'tableau', 'etape' => 0, 'liste' => 'unites'],
                // Menu dépliant « Imports » : un sous-onglet par entité importable, avec son historique.
                ['cle' => 'imports_sites', 'route' => 'admin.referencement.imports.entite', 'parametres' => ['entite' => 'sites'], 'droit' => Droit::ImportsVoir,
                    'icone' => 'registre', 'etape' => 0, 'inclure' => ['admin.sites.excel.*'], 'sous_menu' => 'imports'],
                ['cle' => 'imports_boutiques', 'route' => 'admin.referencement.imports.entite', 'parametres' => ['entite' => 'boutiques'], 'droit' => Droit::ImportsVoir,
                    'icone' => 'registre', 'etape' => 0, 'inclure' => ['admin.sites.boutiques-excel.*'], 'sous_menu' => 'imports'],
                ['cle' => 'types', 'route' => 'admin.sites.types', 'droit' => Droit::ReferentielsBoutiquesVoir, 'icone' => 'referentiels', 'etape' => 0, 'sous_menu' => 'referentiels'],
                ['cle' => 'niveaux', 'route' => 'admin.referencement.niveaux', 'droit' => Droit::ReferentielsBoutiquesVoir, 'icone' => 'tranches', 'etape' => 0,
                    'liste' => 'niveaux', 'sous_menu' => 'referentiels'],
                ['cle' => 'zones', 'route' => 'admin.referencement.zones', 'droit' => Droit::ReferentielsBoutiquesVoir, 'icone' => 'lieu', 'etape' => 0,
                    'exclure' => ['admin.referencement.zones.*'], 'liste' => 'zones', 'sous_menu' => 'referentiels'],
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
                // Contrôle des paiements (CONTRÔLEUR DES PAIEMENTS) ; vérifications d'identité (ADMIN, VALIDATEUR, RÉGULATEUR en lecture).
                ['cle' => 'paiements', 'route' => 'admin.paiements', 'droit' => Droit::PaiementsVoir, 'icone' => 'souscriptions', 'etape' => 0],
                ['cle' => 'kyc', 'route' => 'admin.kyc', 'droit' => Droit::KycVoir, 'icone' => 'kyc', 'etape' => 0],
                ['cle' => 'conformite', 'route' => 'admin.conformite', 'droit' => Droit::ConformiteVoir, 'icone' => 'conformite', 'etape' => 5],
                ['cle' => 'souscriptions', 'route' => 'admin.souscriptions', 'droit' => Droit::SouscriptionsVoir, 'icone' => 'souscriptions', 'etape' => 5],
                ['cle' => 'remboursements', 'route' => 'admin.remboursements', 'droit' => Droit::RemboursementsVoir, 'icone' => 'remboursements', 'etape' => 0],
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
     * réunit ses sous-entrées à la place de la première d'entre elles. Pour le GESTIONNAIRE DES SITES ET BOUTIQUES,
     * dont c'est le travail principal, le menu « Référentiels » passe en tête de son groupe.
     *
     * @param  list<array<string, mixed>>  $entrees
     * @return list<array{type: string, entree?: array, cle?: string, entrees?: list<array>}>
     */
    public static function regrouper(array $entrees, ?User $user = null): array
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

        if ($user?->hasRole(Role::GestionnaireSites)) {
            $tete = fn (array $e) => ($e['cle'] ?? null) === 'referentiels';
            usort($elements, fn (array $a, array $b) => $tete($b) <=> $tete($a));
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

    /** Adresse d'une entrée du menu (route et paramètres éventuels). */
    public static function url(array $entree): string
    {
        return route($entree['route'], $entree['parametres'] ?? []);
    }

    /** L'entrée est-elle celle de la page affichée ? (route et sous-routes, moins les exclusions, plus les inclusions) */
    public static function estActive(array $entree): bool
    {
        if (isset($entree['liste']) && request()->routeIs('admin.referencement.zones.*')) {
            return request()->route('liste') === $entree['liste'];
        }
        // Entrées d'une même route qui diffèrent par un paramètre (sous-onglets des imports).
        if (isset($entree['parametres']) && request()->routeIs($entree['route'])) {
            return collect($entree['parametres'])->every(fn ($v, $k) => request()->route($k) === $v);
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
        // VALIDATEUR : tous les compteurs viennent de la file « À valider », calculée une seule fois pour la page.
        $file = $user->can(Droit::AValiderVoir->value);
        $total = $file ? AValider::total($user) : 0;
        $parElement = $file ? AValider::parElement($user) : [];

        return array_filter([
            // VALIDATEUR : total de la file « À valider » (entrée et vue d'ensemble), puis une valeur par rubrique.
            'a_valider' => $total,
            'menu:a_valider' => $total,
            ...($file ? collect(AValider::compteurs($user))->mapWithKeys(fn (int $n, string $g) => ['a_valider_'.$g => $n])->all() : []),
            'brouillons' => $user->can(Droit::BrouillonsVoir->value) ? SoumissionGroupee::nombreBrouillons($user) : 0,
            // VALIDATEUR : sites soumis ; GESTIONNAIRE : sites rejetés à reprendre.
            'sites' => match (true) {
                $valide => $parElement['site'] ?? 0,
                $gere => Site::query()->where('statut', StatutSite::Brouillon)->whereNotNull('motif_rejet')->count(),
                default => 0,
            },
            'types' => match (true) {
                $valide => $parElement['type'] ?? 0,
                $gere => TypeBoutique::query()->where('statut', StatutTypeBoutique::Rejete)->count(),
                default => 0,
            },
            // VALIDATEUR : niveaux et zones à valider ; GESTIONNAIRE : niveaux et zones rejetés.
            'niveaux' => match (true) {
                $valide => $parElement['niveaux'] ?? 0,
                $gere => Niveau::query()->where('statut', StatutTypeBoutique::Rejete)->count(),
                default => 0,
            },
            'zones' => match (true) {
                $valide => $parElement['zones'] ?? 0,
                $gere => ZoneSite::query()->where('statut', StatutTypeBoutique::Rejete)->count(),
                default => 0,
            },
            // VALIDATEUR : unités à valider ; RÉGULATEUR : unités rejetées à corriger.
            'unites' => match (true) {
                $user->can(Droit::UnitesConfirmer->value) => $parElement['unites'] ?? 0,
                $user->can(Droit::UnitesGerer->value) => Unite::query()->where('statut', StatutTypeBoutique::Rejete)->count(),
                default => 0,
            },
            // VALIDATEUR : moyens de paiement à valider ; RÉGULATEUR : moyens rejetés à corriger.
            'moyens' => match (true) {
                $user->can(Droit::MoyensPaiementConfirmer->value) => $parElement['moyens'] ?? 0,
                $user->can(Droit::MoyensPaiementGerer->value) => MoyenPaiement::query()->where('statut', StatutTypeBoutique::Rejete)->count(),
                default => 0,
            },
            // GESTIONNAIRE : imports de boutiques déposés mais pas encore confirmés (moins de 24 h).
            'imports_boutiques' => $gere ? ImportEnAttente::query()->actifs()->where('entite', 'boutiques')->count() : 0,
            // GESTIONNAIRE : boutiques sans niveau (à compléter avant soumission) et capacités incomplètes.
            'boutiques_referentiel' => $gere ? Boutique::query()->whereNull('niveau')->where('statut', '!=', StatutBoutique::Retiree)->count() : 0,
            'capacites' => $gere ? CapaciteSite::query()->whereNull('nombre_prevu')->whereNull('surface_totale')->count() : 0,
            // RÉGULATEUR : grilles en brouillon ; VALIDATEUR : grilles soumises.
            'grille' => match (true) {
                $user->can(Droit::BoutiquesConfirmer->value) => $parElement['grille'] ?? 0,
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
