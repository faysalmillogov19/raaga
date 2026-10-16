<?php

namespace App\Support;

use App\Enums\Droit;
use App\Enums\Role;
use App\Enums\StatutBoutique;
use App\Enums\StatutGrilleTarifaire;
use App\Enums\StatutSite;
use App\Enums\StatutTypeBoutique;
use App\Http\Controllers\Admin\CautionsRembourserController;
use App\Models\Boutique;
use App\Models\CapaciteSite;
use App\Models\DecisionAvance;
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
    public const ICONES_MENUS = ['referentiels' => 'referentiels', 'modalites' => 'dividendes', 'imports' => 'importer', 'a_valider' => 'taches',
        'tarification' => 'dividendes', 'location' => 'boutiques', 'invest' => 'tranches', 'site_public' => 'plateforme', 'soumissions' => 'taches',
        'gestion_boutiques' => 'boutiques', 'controle' => 'audit', 'actions_a' => 'institutions'];

    /** Rubriques de « À valider » du VALIDATEUR, classées sous quatre intertitres (badge de l'intertitre : somme des rubriques). */
    public const INTERTITRES_A_VALIDER = [
        'referencement' => ['sites', 'imports', 'referentiels', 'autorisations'],
        'finances' => ['finances', 'grilles', 'paiements', 'cautions'],
        'campagnes' => ['campagnes', 'attributions'],
        'contenus' => ['demarches', 'identites'],
    ];

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
                    'droit' => $r['droits'][0], 'icone' => 'taches', 'etape' => 0, 'sous_menu' => 'a_valider', 'grise_si_vide' => true,
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
                // Cautions à rembourser après annulation faute d'avance (RÉGULATEUR propose la retenue ; VALIDATEUR et GESTIONNAIRE
                // consultent, ce dernier sans montant).
                ['cle' => 'cautions', 'route' => 'admin.cautions', 'droit' => Droit::AvancesVoir, 'icone' => 'remboursements', 'etape' => 0],
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
                ['cle' => 'capacites', 'route' => 'admin.referencement.capacites', 'droit' => Droit::CapacitesVoir, 'icone' => 'simulations', 'etape' => 0],
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
     * Menu du RÉGULATEUR, regroupé par domaine en menus dépliants (16/10/2026) : mêmes écrans, mêmes URL, mêmes droits
     * que la définition commune, qui reste celle des autres profils. Les écrans hors des domaines demandés sont rangés
     * dans le domaine le plus proche (Sites, Capacités : Tarification ; Boutiques : Location ; Remboursements et
     * Référentiels de paiement : Modalités ; Actions A, opérations et contrôle : RAAGA Invest ; Journal d'audit et
     * Observations : Mes soumissions).
     *
     * @return array<string, list<array<string, mixed>>>
     */
    public static function regulateur(): array
    {
        $dans = fn (string $menu, string $cle, array $plus = []) => [...self::entree($cle), 'sous_menu' => $menu, ...$plus];
        $libelle = fn (string $cle) => __('admin.menu_regulateur.'.$cle);
        $soumissions = fn (string $cle, string $vue) => ['cle' => $cle, 'route' => 'admin.brouillons', 'requete' => ['vue' => $vue], 'droit' => Droit::BrouillonsVoir,
            'icone' => 'taches', 'etape' => 0, 'sous_menu' => 'soumissions', 'libelle' => $libelle($cle)];
        $finances = ['admin.sites.finances', 'admin.sites.finances.*'];

        return [
            'pilotage' => [
                self::entree('tableau'),
                // Tarification : grille, unités, données financières des sites (liste, puis fiche de chaque site).
                $dans('tarification', 'grille'),
                $dans('tarification', 'unites'),
                ['cle' => 'finances', 'route' => 'admin.sites.finances.liste', 'droit' => Droit::BoutiquesSaisir, 'icone' => 'dividendes', 'etape' => 0,
                    'sous_menu' => 'tarification', 'inclure' => $finances, 'libelle' => $libelle('finances')],
                $dans('tarification', 'sites', ['exclure' => [...self::entree('sites')['exclure'], ...$finances]]),
                $dans('tarification', 'capacites'),
                // Location des boutiques : campagnes (hors grille tarifaire, sous la même route), cautions, boutiques.
                $dans('location', 'boutiques', ['exclure' => ['admin.boutiques.grilles', 'admin.boutiques.grilles.*'], 'libelle' => $libelle('campagnes')]),
                $dans('location', 'cautions'),
                $dans('location', 'boutiques_referentiel'),
                $dans('modalites', 'moyens'),
                $dans('modalites', 'delais'),
                $dans('modalites', 'remboursements'),
                $dans('modalites', 'referentiels', ['libelle' => $libelle('referentiels')]),
                $dans('invest', 'investissement'),
                $dans('invest', 'tranches'),
                $dans('invest', 'interets'),
                $dans('invest', 'simulations'),
                $dans('invest', 'dividendes'),
                $dans('invest', 'souscriptions'),
                $dans('invest', 'kyc'),
                $dans('invest', 'conformite'),
                $dans('invest', 'registre'),
                $dans('invest', 'regulation'),
                $dans('invest', 'dossiers_kyb'),
                $dans('invest', 'conventions'),
                $dans('invest', 'appels_fonds'),
                $dans('invest', 'registre_a'),
                $dans('site_public', 'phase', ['libelle' => $libelle('demarches')]),
                // Mes soumissions : la page « Mes brouillons » filtrée (brouillons, rejetés, en attente du VALIDATEUR).
                $soumissions('mes_brouillons', 'brouillons'),
                $soumissions('rejetes', 'rejetes'),
                $soumissions('en_attente', 'en_attente'),
                $dans('soumissions', 'audit'),
                $dans('soumissions', 'observations'),
            ],
        ];
    }

    /**
     * Menu du VALIDATEUR, finalisé le 16/10/2026 : « À valider » (vue d'ensemble, puis ses rubriques sous quatre
     * intertitres), « Boutiques », « RAAGA Invest », « Actions A », « Contrôle ». Mêmes URL que la définition commune ;
     * les écrans repris par d'autres profils ne lui sont plus accessibles (droits retirés dans Droit::pour()).
     *
     * @return array<string, list<array<string, mixed>>>
     */
    public static function validateur(): array
    {
        $dans = fn (string $menu, string $cle, array $plus = []) => [...self::entree($cle), 'sous_menu' => $menu, ...$plus];
        $libelle = fn (string $cle) => __('admin.menu_validateur.'.$cle);
        $rubriques = [];
        foreach (self::INTERTITRES_A_VALIDER as $intertitre => $groupes) {
            foreach ($groupes as $groupe) {
                $rubriques[] = [...self::entree('a_valider_'.$groupe), 'intertitre' => $intertitre];
            }
        }

        return [
            'pilotage' => [
                self::entree('tableau'),
                self::entree('a_valider'),
                ...$rubriques,
                // Boutiques : attributions à faire, cautions à rembourser, campagnes, consultation du référencement, imports.
                ['cle' => 'attributions_a_faire', 'route' => 'admin.boutiques.attributions', 'droit' => Droit::BoutiquesPiloter, 'icone' => 'boutiques', 'etape' => 0,
                    'sous_menu' => 'gestion_boutiques', 'libelle' => $libelle('attributions_a_faire')],
                $dans('gestion_boutiques', 'cautions'),
                $dans('gestion_boutiques', 'boutiques', ['exclure' => ['admin.boutiques.attributions', 'admin.boutiques.grilles', 'admin.boutiques.grilles.*'],
                    'libelle' => $libelle('campagnes')]),
                $dans('gestion_boutiques', 'sites', ['libelle' => $libelle('sites')]),
                $dans('gestion_boutiques', 'boutiques_referentiel', ['libelle' => $libelle('boutiques_referentiel')]),
                $dans('gestion_boutiques', 'imports_sites', ['libelle' => $libelle('imports_sites')]),
                $dans('gestion_boutiques', 'imports_boutiques', ['libelle' => $libelle('imports_boutiques')]),
                ...array_map(fn (string $cle) => $dans('invest', $cle), ['investissement', 'tranches', 'dividendes', 'simulations', 'registre']),
                ...array_map(fn (string $cle) => $dans('actions_a', $cle), ['dossiers_kyb', 'conventions', 'appels_fonds', 'registre_a']),
                // Contrôle : historique de mes décisions, observations, alertes conformité, journal d'audit.
                ['cle' => 'historique', 'route' => 'admin.historique-decisions', 'droit' => Droit::AValiderVoir, 'icone' => 'audit', 'etape' => 0,
                    'sous_menu' => 'controle', 'libelle' => $libelle('historique')],
                ...array_map(fn (string $cle) => $dans('controle', $cle), ['observations', 'conformite', 'audit']),
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
            match (true) {
                $user->hasRole(Role::Regulateur) => self::regulateur(),
                $user->hasRole(Role::Validateur) => self::validateur(),
                default => self::definition(),
            },
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

    /** Adresse d'une entrée du menu (route, paramètres et filtre éventuels). */
    public static function url(array $entree): string
    {
        return route($entree['route'], [...($entree['parametres'] ?? []), ...($entree['requete'] ?? [])]);
    }

    /** L'entrée est-elle celle de la page affichée ? (route et sous-routes, moins les exclusions, plus les inclusions) */
    public static function estActive(array $entree): bool
    {
        // Entrées d'une même page qui diffèrent par un filtre (« Mes soumissions ») ; sans filtre : la première.
        if (isset($entree['requete'])) {
            return request()->routeIs($entree['route'])
                && collect($entree['requete'])->every(fn ($v, $k) => request()->query($k, $v === 'brouillons' ? $v : null) === $v);
        }
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
        $regulateur = $user->hasRole(Role::Regulateur);

        $badges = array_filter([
            // VALIDATEUR : total de la file « À valider » (entrée et vue d'ensemble), puis une valeur par rubrique.
            'a_valider' => $total,
            'menu:a_valider' => $total,
            ...($file ? collect(AValider::compteurs($user))->mapWithKeys(fn (int $n, string $g) => ['a_valider_'.$g => $n])->all() : []),
            // VALIDATEUR : « Boutiques › Attributions à faire » = rubrique « Attributions » (même file, aucune requête de plus).
            'attributions_a_faire' => $file ? (AValider::compteurs($user)['attributions'] ?? 0) : 0,
            'brouillons' => $user->can(Droit::BrouillonsVoir->value) && ! $regulateur ? SoumissionGroupee::nombreBrouillons($user) : 0,
            // RÉGULATEUR (« Mes soumissions ») : brouillons et rejetés comptés à part, sommés sur le menu parent.
            'mes_brouillons' => $regulateur ? SoumissionGroupee::nombreBrouillons($user, 'brouillons') : 0,
            'rejetes' => $regulateur ? SoumissionGroupee::nombreBrouillons($user, 'rejetes') : 0,
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
            // RÉGULATEUR : cautions en attente de retenue sans proposition en attente du VALIDATEUR.
            'cautions' => $user->can(Droit::AvancesProposer->value)
                ? CautionsRembourserController::requete()->whereDoesntHave('decisions', fn ($q) => $q->where('statut', DecisionAvance::PROPOSEE))->count() : 0,
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

        // VALIDATEUR : sites et grilles soumis sont déjà comptés dans « À valider » ; leurs entrées de consultation
        // (menu « Boutiques ») n'en reprennent pas le badge, pour ne pas le compter deux fois sur le parent.
        if ($user->hasRole(Role::Validateur)) {
            unset($badges['sites'], $badges['grille']);
        }

        return $badges;
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
