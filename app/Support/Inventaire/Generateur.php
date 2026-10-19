<?php

namespace App\Support\Inventaire;

use App\Enums\Droit;
use App\Enums\Role;
use App\Models\Role as RoleModele;
use App\Models\User;
use App\Support\MenuBackoffice;
use Closure;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route as Routes;
use ReflectionMethod;
use Throwable;

/**
 * Parties automatiques des inventaires du front-office et du back-office : routes (vues, middleware), menus par profil,
 * matrice des droits (Droit::pour, source des Gates), composants Blade et Alpine, chiffres de la base locale (requêtes
 * en lecture seule, dans une transaction en lecture seule, toujours annulée). Aucune donnée personnelle n'est lue ;
 * tout le texte produit passe en plus par Masquage.
 */
final class Generateur
{
    /** Espace fine insécable : sépare les milliers sans former une suite de chiffres prise pour un identifiant. */
    private const ESPACE = "\u{202F}";

    /** @return array<string, string> nom du bloc => contenu Markdown, pour docs/INVENTAIRE-FRONT-OFFICE.md */
    public function frontOffice(): array
    {
        return [
            'routes-publiques' => $this->routesPubliques(),
            'composants' => $this->composants(fn (string $nom) => ! str_starts_with($nom, 'admin.')),
            'chiffres-base' => $this->chiffresBase(),
        ];
    }

    /** @return array<string, string> nom du bloc => contenu Markdown, pour docs/INVENTAIRE-BACK-OFFICE.md */
    public function backOffice(): array
    {
        return [
            'matrice-droits' => $this->matriceDroits(),
            'menus' => $this->menus(),
            'routes-admin' => $this->routesAdmin(),
            'composants-admin' => $this->composants(fn (string $nom) => str_starts_with($nom, 'admin.') || str_starts_with($nom, 'tableau')),
        ];
    }

    // ------------------------------------------------------------------ Routes

    private function routesPubliques(): string
    {
        $routes = collect(Routes::getRoutes()->getRoutes());
        $publiques = $routes->reject(fn (Route $r) => $this->espace($r) !== 'public');
        [$connecte, $libre] = $publiques->partition(fn (Route $r) => in_array('auth', $r->gatherMiddleware(), true));

        $comptes = $routes->countBy(fn (Route $r) => $this->espace($r));

        return implode("\n\n", [
            '### Routes accessibles sans connexion ('.$libre->count().')',
            $this->tableauRoutes($libre->all()),
            '### Routes de l’espace citoyen connecté ('.$connecte->count().')',
            $this->tableauRoutes($connecte->all()),
            '### Autres espaces',
            $this->tableau(['Espace', 'Nombre de routes'], [
                ['Back-office /admin', $comptes->get('admin', 0)],
                ['Investisseurs institutionnels /institutionnel', $comptes->get('institutionnel', 0)],
                ['Toutes les routes', $routes->count()],
            ]),
        ]);
    }

    private function routesAdmin(): string
    {
        $admin = collect(Routes::getRoutes()->getRoutes())->filter(fn (Route $r) => $this->espace($r) === 'admin');

        return implode("\n\n", [
            '### Routes du back-office ('.$admin->count().')',
            $this->tableauRoutes($admin->all()),
        ]);
    }

    private function espace(Route $route): string
    {
        $uri = $route->uri();

        return match (true) {
            $uri === 'admin' || str_starts_with($uri, 'admin/') => 'admin',
            $uri === 'institutionnel' || str_starts_with($uri, 'institutionnel/') => 'institutionnel',
            default => 'public',
        };
    }

    /** @param  list<Route>  $routes */
    private function tableauRoutes(array $routes): string
    {
        usort($routes, fn (Route $a, Route $b) => [$a->uri(), $a->methods()[0]] <=> [$b->uri(), $b->methods()[0]]);

        return $this->tableau(['Méthode', 'URL', 'Nom de route', 'Action', 'Vue Blade', 'Middleware'], array_map(fn (Route $r) => [
            implode(', ', array_diff($r->methods(), ['HEAD'])),
            '/'.ltrim($r->uri(), '/'),
            $r->getName() ?? '',
            $this->action($r),
            implode(', ', $this->vues($r)),
            implode(', ', array_values(array_filter($r->gatherMiddleware(), fn ($m) => is_string($m) && $m !== 'web'))),
        ], $routes));
    }

    private function action(Route $route): string
    {
        $action = $route->getActionName();
        if ($action === 'Closure') {
            return isset($route->defaults['view']) ? 'Route::view' : (isset($route->defaults['destination']) ? 'Redirection vers '.$route->defaults['destination'] : 'Closure');
        }

        return str_replace('App\\Http\\Controllers\\', '', $action);
    }

    /** Vues rendues, lues dans le code de la méthode du contrôleur (view('…')) ou dans Route::view. @return list<string> */
    private function vues(Route $route): array
    {
        if (isset($route->defaults['view'])) {
            return [(string) $route->defaults['view']];
        }
        $action = $route->getActionName();
        if ($action === 'Closure') {
            return [];
        }
        [$classe, $methode] = str_contains($action, '@') ? explode('@', $action, 2) : [$action, '__invoke'];
        try {
            $reflexion = new ReflectionMethod($classe, $methode);
            $lignes = array_slice(file((string) $reflexion->getFileName()), $reflexion->getStartLine() - 1, $reflexion->getEndLine() - $reflexion->getStartLine() + 1);
            preg_match_all("/view\\(\\s*'([a-z0-9._-]+)'/i", implode('', $lignes), $m);

            return array_values(array_unique($m[1]));
        } catch (Throwable) {
            return [];
        }
    }

    // ------------------------------------------------------------------ Droits et menus

    private function matriceDroits(): string
    {
        $roles = Role::backoffice();
        $lignes = array_map(fn (Droit $d) => [
            $d->name,
            $d->value,
            $d->label(),
            ...array_map(fn (Role $r) => in_array($d, Droit::pour($r), true) ? 'oui' : '', $roles),
        ], Droit::cases());

        return implode("\n\n", [
            'Source : App\Enums\Droit::pour(), dont chaque droit est une Gate (AppServiceProvider). Aucune policy dans le projet.',
            $this->tableau(['Droit', 'Valeur', 'Ce qu’il permet', ...array_map(fn (Role $r) => $r->label(), $roles)], $lignes),
            'Profils hors back-office : '.implode(', ', array_map(fn (Role $r) => $r->label().' ('.count(Droit::pour($r)).' droit(s))',
                array_values(array_filter(Role::cases(), fn (Role $r) => ! in_array($r, $roles, true))))).'.',
        ]);
    }

    private function menus(): string
    {
        $parties = [];
        foreach (Role::backoffice() as $role) {
            $user = (new User)->forceFill(['actif' => true]);
            $user->setRelation('role', new RoleModele(['code' => $role]));
            $lignes = [];
            $ordre = 0;
            foreach (MenuBackoffice::pour($user) as $groupe => $entrees) {
                foreach ($entrees as $e) {
                    $lignes[] = [
                        ++$ordre,
                        __('admin.groupes.'.$groupe),
                        isset($e['sous_menu']) ? __('admin.menus_deroulants.'.$e['sous_menu']) : '',
                        (string) ($e['libelle'] ?? __('admin.menu.'.$e['cle'])),
                        $e['icone'] ?? '',
                        $e['route'].(isset($e['parametres']) ? ' ('.http_build_query($e['parametres'], '', ', ').')' : '')
                            .(isset($e['requete']) ? ' ?'.http_build_query($e['requete']) : ''),
                        $e['droit']->value,
                    ];
                }
            }
            $parties[] = '### '.$role->label()."\n\n".$this->tableau(['Ordre', 'Groupe', 'Menu dépliant', 'Libellé', 'Icône', 'Route', 'Droit'], $lignes);
        }

        return 'Entrées visibles d’après MenuBackoffice::pour() (droits, entrées masquées et pages en préparation retirées). Les compteurs dépendent des données et ne sont pas repris ici.'
            ."\n\n".implode("\n\n", $parties);
    }

    // ------------------------------------------------------------------ Composants

    /** @param  Closure(string): bool  $retenir */
    private function composants(Closure $retenir): string
    {
        $dossier = resource_path('views/components');
        $lignes = [];
        foreach (File::allFiles($dossier) as $fichier) {
            if (! str_ends_with($fichier->getFilename(), '.blade.php')) {
                continue;
            }
            $relatif = str_replace('\\', '/', $fichier->getRelativePathname());
            $nom = str_replace('/', '.', substr($relatif, 0, -strlen('.blade.php')));
            if (! $retenir($nom)) {
                continue;
            }
            preg_match('/@props\(\s*\[(.*?)\]\s*\)/s', $fichier->getContents(), $m);
            $props = isset($m[1]) ? trim((string) preg_replace('/\s+/', ' ', $m[1]), ' ,') : '';
            $lignes[] = ['<x-'.$nom.'>', 'resources/views/components/'.$relatif, $props];
        }
        usort($lignes, fn ($a, $b) => $a[0] <=> $b[0]);

        $parties = ['### Composants Blade ('.count($lignes).')', $this->tableau(['Composant', 'Fichier', 'Paramètres (@props)'], $lignes)];
        if ($retenir('public.layout')) {
            $js = (string) @file_get_contents(public_path('js/app.js'));
            $alpine = [];
            foreach (preg_split('/\R/', $js) ?: [] as $i => $ligne) {
                if (preg_match("/composant\\(\\s*'([A-Za-z0-9_]+)'/", $ligne, $c)) {
                    $alpine[] = [$c[1], 'public/js/app.js:'.($i + 1)];
                }
            }
            $parties[] = '### Composants Alpine ('.count($alpine).')';
            $parties[] = $this->tableau(['Nom (x-data)', 'Déclaration'], $alpine);
        }

        return implode("\n\n", $parties);
    }

    // ------------------------------------------------------------------ Chiffres de la base

    /** Requêtes en lecture seule : titre => SQL. Aucune colonne de données personnelles n'est lue. */
    public const REQUETES = [
        'Sites et boutiques' => "select s.code, s.nom, s.statut, count(b.id) as total, count(*) filter (where b.statut='DISPONIBLE') as libres, count(*) filter (where b.statut='PRE_RESERVEE') as pre_reservees, count(*) filter (where b.statut='RESERVEE') as reservees, count(*) filter (where b.statut='ATTRIBUEE') as attribuees from sites s left join boutiques b on b.site_id = s.id and b.deleted_at is null group by s.id, s.code, s.nom, s.statut order by s.code",
        'Boutiques par niveau' => "select b.niveau, n.libelle, count(b.id) as total, count(*) filter (where b.statut='DISPONIBLE') as libres, count(*) filter (where b.statut in ('PRE_RESERVEE','RESERVEE')) as reservees, count(*) filter (where b.statut='ATTRIBUEE') as attribuees from boutiques b left join niveaux n on n.code = b.niveau where b.deleted_at is null group by b.niveau, n.libelle, n.rang order by n.rang",
        'Boutiques par niveau et bloc' => "select b.niveau, b.bloc, count(*) as total, count(*) filter (where b.statut='DISPONIBLE') as libres from boutiques b where b.deleted_at is null group by b.niveau, b.bloc order by b.niveau, b.bloc",
        'Types de boutiques' => "select t.code, t.libelle, t.statut, t.actif, t.attribuable, count(b.id) as total, count(*) filter (where b.statut='DISPONIBLE') as libres, count(*) filter (where b.statut in ('PRE_RESERVEE','RESERVEE')) as reservees, count(*) filter (where b.statut='ATTRIBUEE') as attribuees from types_boutiques t left join boutiques b on b.type_boutique_id = t.id and b.deleted_at is null group by t.id, t.code, t.libelle, t.statut, t.actif, t.attribuable, t.ordre order by t.ordre, t.libelle",
        'Grilles tarifaires' => 'select s.code as site, g.statut, g.version, g.valide_le from grilles_tarifaires g join sites s on s.id = g.site_id order by s.code',
        'Campagnes de réservation' => 'select c.id, c.nom, c.statut, c.date_ouverture, c.date_cloture, c.ouverte_le, c.cloturee_le, c.types::text as types, c.limites::text as limites from campagnes_boutiques c order by c.id',
        'Réservations par statut' => 'select statut, count(*) as nombre from reservations_boutiques group by statut order by statut',
        'Tranches RAAGA Invest' => 'select t.id, t.nom, t.statut, t.valeur_unitaire, t.objectif_montant, t.date_ouverture, t.date_cloture, t.regle_arret, t.methode_allocation from tranches t order by t.id',
        'Collecte RAAGA Invest (souscriptions payées)' => "select t.id as tranche, count(s.id) filter (where s.statut in ('PAYEE','ALLOUEE')) as souscriptions_payees, coalesce(sum(s.montant) filter (where s.statut in ('PAYEE','ALLOUEE')), 0) as montant_collecte, count(distinct s.user_id) filter (where s.statut in ('PAYEE','ALLOUEE')) as souscripteurs from tranches t left join souscriptions s on s.tranche_id = t.id group by t.id order by t.id",
        'Moyens de paiement' => 'select m.ordre, m.code, m.libelle, m.famille, m.actif, m.statut from moyens_paiement m order by m.ordre, m.code',
        'Délais de paiement confirmés' => 'select p.statut, p.version, p.delai_caution_secondes, p.delai_complement_secondes, p.delai_avance_secondes from parametres_paiements p order by p.id',
        'Décisions de phase (RAAGA Invest)' => 'select d.presentation_actions, d.souscription_actions, d.statut, d.decide_le from decisions_phase d order by d.id desc limit 3',
    ];

    private function chiffresBase(): string
    {
        $connexion = DB::connection();
        $niveau = $connexion->transactionLevel();
        $connexion->beginTransaction();
        try {
            // Transaction en lecture seule (PostgreSQL) : toute écriture serait refusée par la base elle-même.
            if ($niveau === 0 && $connexion->getDriverName() === 'pgsql') {
                $connexion->statement('SET TRANSACTION READ ONLY');
            }
            $parties = ['Requêtes exécutées dans une transaction en lecture seule, toujours annulée (ROLLBACK). Dates au format jj/mm/aaaa.'];
            foreach (self::REQUETES as $titre => $sql) {
                $lignes = array_map(fn ($r) => (array) $r, $connexion->select($sql));
                $parties[] = '### '.$titre;
                $parties[] = "```sql\n".$sql.";\n```";
                $parties[] = $lignes === [] ? 'Aucune ligne.' : $this->tableau(array_keys($lignes[0]), array_map(fn (array $l) => array_map(fn ($v) => $this->valeur($v), array_values($l)), $lignes));
            }
        } finally {
            $connexion->rollBack();
        }

        return implode("\n\n", $parties);
    }

    private function valeur(mixed $v): string
    {
        return match (true) {
            $v === null => 'NULL',
            is_bool($v) => $v ? 'oui' : 'non',
            is_int($v), is_string($v) && preg_match('/^-?\d+$/', $v) => number_format((int) $v, 0, ',', self::ESPACE),
            is_string($v) && preg_match('/^(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):(\d{2})/', $v, $d) => "{$d[3]}/{$d[2]}/{$d[1]} {$d[4]}:{$d[5]}",
            default => (string) $v,
        };
    }

    // ------------------------------------------------------------------ Markdown

    /** @param  list<list<mixed>>  $lignes */
    private function tableau(array $entetes, array $lignes): string
    {
        $cellule = fn ($v) => str_replace(['|', "\n"], ['\|', ' '], (string) $v);
        $sortie = ['| '.implode(' | ', array_map($cellule, $entetes)).' |', '|'.str_repeat('---|', count($entetes))];
        foreach ($lignes as $ligne) {
            $sortie[] = '| '.implode(' | ', array_map($cellule, $ligne)).' |';
        }

        return $lignes === [] ? 'Aucune ligne.' : implode("\n", $sortie);
    }
}
