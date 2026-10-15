<?php

namespace App\Support;

use App\Contracts\Parametrable;
use App\Enums\DecisionParametrage;
use App\Enums\Droit;
use App\Enums\StatutCampagneBoutiques;
use App\Enums\StatutGrilleTarifaire;
use App\Enums\StatutSite;
use App\Enums\StatutTypeBoutique;
use App\Models\CampagneBoutiques;
use App\Models\DecisionPhase;
use App\Models\GrilleTarifaire;
use App\Models\Niveau;
use App\Models\ParametrageDecision;
use App\Models\Site;
use App\Models\SiteFinance;
use App\Models\TypeBoutique;
use App\Models\Unite;
use App\Models\User;
use App\Models\ZoneSite;
use App\Services\Parametrage\WorkflowParametrage;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * File « À valider » du VALIDATEUR : tout ce qui attend une décision, regroupé par type d'élément. Chaque élément porte
 * une clé « type:identifiant ». Un élément soumis par le VALIDATEUR lui-même reste affiché, grisé, sans décision
 * possible (quatre yeux). Campagnes, démarches du site public et données financières : décision une par une seulement.
 */
final class AValider
{
    /** Ordre des groupes. */
    public const GROUPES = ['sites', 'grilles', 'finances', 'referentiels', 'campagnes', 'demarches', 'autorisations'];

    /** Éléments sensibles : jamais de décision groupée (cases à cocher masquées, refus 422 côté serveur). */
    public const NON_GROUPABLES = ['campagne', 'phase', 'finances'];

    /**
     * @return array<string, list<array{cle: string, element: string, libelle: string, auteur_id: ?int, par: ?string, date: ?CarbonInterface, url: string, moi: bool, groupable: bool}>>
     */
    public static function pour(User $user): array
    {
        $g = array_fill_keys(self::GROUPES, []);
        $nomSite = fn (?Site $s) => $s ? ($s->code ? $s->code.' · ' : '').$s->nom : '';
        $e = fn (string $cle, string $element, string $libelle, ?int $auteur, ?CarbonInterface $date, string $url) => self::element($user, $cle, $element, $libelle, $auteur, $date, $url);

        if ($user->can(Droit::SitesConfirmer->value)) {
            foreach (Site::query()->where('statut', StatutSite::Soumis)->get() as $s) {
                $g['sites'][] = $e('site:'.$s->id, 'site', $nomSite($s), $s->soumis_par, $s->soumis_le, route('admin.sites.show', $s));
            }
            foreach (TypeBoutique::query()->where(fn ($q) => $q->where('statut', StatutTypeBoutique::AValider)->orWhereNotNull('attribuable_demande'))->get() as $t) {
                $g['referentiels'][] = $e('type:'.$t->id, 'type', ($t->code ? $t->code.' · ' : '').$t->libelle, $t->demande_par, $t->demande_le ?? $t->updated_at, route('admin.sites.types.show', $t));
            }
            foreach ([Niveau::class => 'niveaux', ZoneSite::class => 'zones'] as $classe => $liste) {
                foreach ($classe::query()->where('statut', StatutTypeBoutique::AValider)->get() as $r) {
                    $g['referentiels'][] = $e($liste.':'.$r->id, $liste, $r->code.' · '.$r->libelle, $r->demande_par, $r->updated_at, route('admin.referencement.zones.show', [$liste, $r->id]));
                }
            }
            foreach (Site::query()->where('statut', StatutSite::Confirme)->whereNotNull('revision_demandee_le')->get() as $s) {
                $g['autorisations'][] = $e('autorisation:'.$s->id, 'autorisation', $nomSite($s).' : « '.$s->revision_demande_motif.' »', $s->revision_demandee_par, $s->revision_demandee_le, route('admin.sites.show', $s));
            }
        }
        if ($user->can(Droit::UnitesConfirmer->value)) {
            foreach (Unite::query()->where('statut', StatutTypeBoutique::AValider)->get() as $u) {
                $g['referentiels'][] = $e('unites:'.$u->id, 'unites', $u->code.' · '.$u->libelle, $u->demande_par, $u->updated_at, route('admin.referencement.zones.show', ['unites', $u->id]));
            }
        }
        if ($user->can(Droit::BoutiquesConfirmer->value)) {
            foreach (GrilleTarifaire::query()->where('statut', StatutGrilleTarifaire::Soumis)->with('site')->get() as $gr) {
                $g['grilles'][] = $e('grille:'.$gr->site_id, 'grille', $nomSite($gr->site), $gr->soumis_par, $gr->soumis_le, route('admin.boutiques.grilles.show', $gr->site_id));
            }
            foreach (SiteFinance::query()->where('statut', StatutGrilleTarifaire::Soumis)->with('site')->get() as $f) {
                $g['finances'][] = $e('finances:'.$f->site_id, 'finances', $nomSite($f->site), $f->soumis_par, $f->soumis_le, route('admin.sites.finances', $f->site_id));
            }
            foreach (CampagneBoutiques::query()->where('statut', StatutCampagneBoutiques::Soumise)->get() as $c) {
                $g['campagnes'][] = $e('campagne:'.$c->id, 'campagne', $c->nom, $c->soumis_par, $c->soumise_le, route('admin.boutiques.show', $c));
            }
        }
        if ($user->can(Droit::PhaseConfirmer->value)) {
            foreach (DecisionPhase::query()->where('statut', DecisionPhase::SOUMISE)->get() as $d) {
                $g['demarches'][] = $e('phase:'.$d->id, 'phase', self::libellePhase($d), $d->propose_par, $d->created_at, route('admin.phase'));
            }
        }

        foreach ($g as &$elements) {
            usort($elements, fn ($a, $b) => ($a['date']?->timestamp ?? 0) <=> ($b['date']?->timestamp ?? 0));
        }

        return $g;
    }

    /** Nombre d'éléments sur lesquels l'utilisateur peut décider (badge du menu). */
    public static function total(User $user): int
    {
        return collect(self::pour($user))->flatten(1)->where('moi', false)->count();
    }

    /** Élément encore en attente, ou null (déjà traité, inconnu ou hors des droits de l'utilisateur). */
    public static function trouver(User $user, string $cle): ?array
    {
        return collect(self::pour($user))->flatten(1)->firstWhere('cle', $cle);
    }

    /** Groupes visibles pour ce profil (ceux dont il détient le droit de décision). @return list<string> */
    public static function groupesVisibles(User $user): array
    {
        $droits = [
            'sites' => Droit::SitesConfirmer, 'grilles' => Droit::BoutiquesConfirmer, 'finances' => Droit::BoutiquesConfirmer,
            'referentiels' => Droit::SitesConfirmer, 'campagnes' => Droit::BoutiquesConfirmer, 'demarches' => Droit::PhaseConfirmer,
            'autorisations' => Droit::SitesConfirmer,
        ];

        return array_values(array_filter(self::GROUPES, fn (string $g) => $user->can($droits[$g]->value)
            || ($g === 'referentiels' && $user->can(Droit::UnitesConfirmer->value))));
    }

    /**
     * Panneau de détail : informations, différences avec la dernière version confirmée (avant → après), pièces jointes,
     * historique (versions et décisions).
     *
     * @return array{element: array, infos: array<string, ?string>, differentiel: array, libelles: array, premiere: bool, pieces: list<array{libelle: string, url: ?string}>, versions: Collection, decisions: Collection}
     */
    public static function detail(User $user, array $element): array
    {
        [$type, $id] = explode(':', $element['cle']);
        $id = (int) $id;
        $vide = ['infos' => [], 'differentiel' => [], 'libelles' => [], 'premiere' => false, 'pieces' => [], 'versions' => collect(), 'decisions' => collect()];
        $champsSite = __('admin.sites.champs');

        $d = match ($type) {
            'site', 'autorisation' => (function () use ($id, $type, $champsSite) {
                $site = Site::query()->findOrFail($id);
                $infos = [
                    ucfirst($champsSite['code'] ?? 'code') => $site->code, ucfirst($champsSite['nom'] ?? 'nom') => $site->nom,
                    ucfirst($champsSite['ville'] ?? 'ville') => $site->ville, __('admin.parametrage.statut') => $site->statut->label(),
                    __('admin.a_valider.detail.boutiques') => format_nombre($site->boutiques()->count()),
                ];
                if ($type === 'autorisation') {
                    $infos[__('admin.a_valider.detail.motif_demande')] = $site->revision_demande_motif;
                }

                return [...self::parametrable($site, $champsSite, $type === 'site'), 'infos' => $infos,
                    'pieces' => $site->reference_plans ? [['libelle' => __('admin.a_valider.detail.plans', ['reference' => $site->reference_plans, 'version' => $site->version_plans ?? '']), 'url' => null]] : []];
            })(),
            'grille' => (function () use ($id) {
                $grille = GrilleTarifaire::query()->where('site_id', $id)->with('site')->firstOrFail();
                $d = self::parametrable($grille, __('admin.grilles.champs'), true);
                unset($d['differentiel']['lignes']);

                return [...$d, 'infos' => [__('admin.a_valider.detail.site') => $grille->site->nom, __('admin.parametrage.statut') => $grille->statut->label(),
                    __('admin.a_valider.detail.lignes') => format_nombre($grille->lignes()->count())]];
            })(),
            'finances' => (function () use ($id, $champsSite) {
                $finances = SiteFinance::query()->where('site_id', $id)->with('site')->firstOrFail();

                return [...self::parametrable($finances, $champsSite, true), 'infos' => [__('admin.a_valider.detail.site') => $finances->site->nom, __('admin.parametrage.statut') => $finances->statut->label()]];
            })(),
            'campagne' => (function () use ($id) {
                $c = CampagneBoutiques::query()->findOrFail($id);

                return [...self::parametrable($c, __('admin.boutiques.champs'), true), 'infos' => [
                    __('admin.a_valider.detail.nom') => $c->nom, __('admin.parametrage.statut') => $c->statut->label(),
                    __('admin.a_valider.detail.ouverture') => $c->date_ouverture?->format('d/m/Y'), __('admin.a_valider.detail.cloture') => $c->date_cloture?->format('d/m/Y'),
                ]];
            })(),
            'type' => (function () use ($id) {
                $t = TypeBoutique::query()->findOrFail($id);
                $versions = $t->versions()->with('auteur')->get();

                return [...self::referentiel($versions, __('admin.types.champs')), 'infos' => [
                    __('admin.a_valider.detail.code') => $t->code, __('admin.a_valider.detail.libelle') => $t->libelle,
                    __('admin.a_valider.detail.description') => $t->description, __('admin.parametrage.statut') => $t->statut->label(),
                ]];
            })(),
            'niveaux', 'zones', 'unites' => (function () use ($id, $type) {
                $r = ['niveaux' => Niveau::class, 'zones' => ZoneSite::class, 'unites' => Unite::class][$type]::query()->findOrFail($id);

                return [...self::referentiel($r->versions(), __('admin.zones.colonnes')), 'infos' => [
                    __('admin.a_valider.detail.code') => $r->code, __('admin.a_valider.detail.libelle') => $r->libelle,
                    __('admin.a_valider.detail.description') => $r->description ?? null, __('admin.parametrage.statut') => $r->statut->label(),
                ]];
            })(),
            'phase' => (function () use ($id) {
                $d = DecisionPhase::query()->findOrFail($id);
                $p = __('admin.phase.parametres');
                $oui = fn (bool $v) => __('admin.phase.'.($v ? 'oui' : 'non'));
                $avant = ['presentation_actions' => $oui(Phase::presentationActions()), 'souscription_actions' => $oui(Phase::souscriptionParametre())];
                $apres = ['presentation_actions' => $oui($d->presentation_actions), 'souscription_actions' => $oui($d->souscription_actions)];

                return ['differentiel' => array_filter(array_map(fn ($k) => [$avant[$k], $apres[$k]], array_combine(array_keys($apres), array_keys($apres))), fn ($v) => $v[0] !== $v[1]),
                    'libelles' => $p, 'premiere' => false, 'pieces' => [], 'versions' => collect(), 'decisions' => collect(),
                    'infos' => [__('admin.phase.motif') => $d->motif]];
            })(),
        };

        return ['element' => $element, ...$vide, ...$d];
    }

    /** Versions d'un paramétrage (circuit commun) : différences avec la dernière version confirmée, décisions. */
    private static function parametrable(Parametrable $objet, array $libelles, bool $soumis): array
    {
        $versions = $objet->versions()->with('auteur')->get()->sortBy('numero')->values();
        $decisions = ParametrageDecision::query()->where('objet_type', $objet->typeParametrage())->where('objet_id', $objet->getKey())->with('decideur')->orderByDesc('id')->get();
        $numeroConfirme = $decisions->firstWhere('decision', DecisionParametrage::Confirme)?->version;
        $confirmee = $numeroConfirme ? $versions->firstWhere('numero', $numeroConfirme) : null;
        $soumise = $versions->last();
        $differentiel = $soumis && $soumise ? WorkflowParametrage::differentiel($confirmee?->parametres ?? [], $soumise->parametres) : [];
        if (isset($differentiel['fiches'])) {
            [$avant, $apres] = array_map(fn ($f) => (array) $f, $differentiel['fiches']);
            $modifiees = collect(array_keys($avant + $apres))->filter(fn ($code) => ($avant[$code] ?? null) !== ($apres[$code] ?? null))->sort(SORT_NATURAL);
            $differentiel['fiches'] = [null, $modifiees->take(30)->implode(', ').($modifiees->count() > 30 ? '…' : '')];
        }

        return ['differentiel' => $differentiel, 'libelles' => $libelles, 'premiere' => $soumis && $confirmee === null,
            'versions' => $versions->reverse()->values(), 'decisions' => $decisions];
    }

    /** Versions figées d'un référentiel : différences entre la version soumise et la précédente. */
    private static function referentiel(Collection $versions, array $libelles): array
    {
        $versions = $versions->sortByDesc('numero')->values();
        $soumise = $versions->get(0);
        $precedente = $versions->get(1);
        $differentiel = $soumise ? WorkflowParametrage::differentiel((array) ($precedente?->parametres ?? []), (array) $soumise->parametres) : [];

        return ['differentiel' => $differentiel, 'libelles' => $libelles, 'premiere' => $precedente === null, 'versions' => $versions, 'decisions' => collect()];
    }

    private static function libellePhase(DecisionPhase $d): string
    {
        return __('admin.phase.parametres.presentation_actions').' : '.__('admin.phase.'.($d->presentation_actions ? 'oui' : 'non'))
            .' · '.__('admin.phase.parametres.souscription_actions').' : '.__('admin.phase.'.($d->souscription_actions ? 'oui' : 'non'));
    }

    /** @return array{cle: string, element: string, libelle: string, auteur_id: ?int, par: ?string, date: ?CarbonInterface, url: string, moi: bool, groupable: bool} */
    private static function element(User $user, string $cle, string $element, string $libelle, ?int $auteur, ?CarbonInterface $date, string $url): array
    {
        return [
            'cle' => $cle, 'element' => $element, 'libelle' => $libelle, 'auteur_id' => $auteur,
            'par' => $auteur ? User::query()->whereKey($auteur)->value('name') : null, 'date' => $date, 'url' => $url,
            // Quatre yeux : jamais de décision sur un élément que l'on a soumis soi-même.
            'moi' => $auteur !== null && $auteur === $user->id,
            'groupable' => ! in_array($element, self::NON_GROUPABLES, true),
        ];
    }
}
