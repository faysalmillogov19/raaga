<?php

namespace App\Support;

use App\Contracts\Parametrable;
use App\Enums\DecisionParametrage;
use App\Enums\Droit;
use App\Enums\StatutCampagneBoutiques;
use App\Enums\StatutGrilleTarifaire;
use App\Enums\StatutPaiement;
use App\Enums\StatutReservationBoutique;
use App\Enums\StatutSite;
use App\Enums\StatutTypeBoutique;
use App\Models\AuditLog;
use App\Models\CampagneBoutiques;
use App\Models\CorrectionPaiement;
use App\Models\DecisionAvance;
use App\Models\DecisionPhase;
use App\Models\GrilleTarifaire;
use App\Models\ImportValidation;
use App\Models\MoyenPaiement;
use App\Models\Niveau;
use App\Models\ParametrageDecision;
use App\Models\ParametresInvestissement;
use App\Models\ParametresPaiements;
use App\Models\Remboursement;
use App\Models\ReservationBoutique;
use App\Models\Site;
use App\Models\SiteFinance;
use App\Models\TypeBoutique;
use App\Models\Unite;
use App\Models\User;
use App\Models\VerificationKyc;
use App\Models\ZoneSite;
use App\Services\Kyc\VerificationIdentite;
use App\Services\Parametrage\WorkflowParametrage;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * File « À valider » du VALIDATEUR : tout ce qui attend une décision, rangé par rubrique. Chaque élément porte une clé
 * « type:identifiant ». Un élément soumis par le VALIDATEUR lui-même reste affiché, grisé, sans décision possible
 * (quatre yeux). Rubriques sensibles (campagnes, démarches du site public, données financières) : décision une par une.
 *
 * Ajouter une rubrique (dossiers KYC, rapprochement des cautions, tranches…) = déclarer UNE entrée dans rubriques() :
 * le menu « À valider », la route, la page, les compteurs et le groupage en découlent.
 */
final class AValider
{
    /**
     * Rubriques, dans l'ordre du menu : groupe => [adresse (slug), droits de décision (l'un suffit), décision groupée
     * permise, types d'éléments, source des éléments].
     *
     * @return array<string, array{slug: string, droits: list<Droit>, groupable: bool, elements: list<string>, source: \Closure}>
     */
    public static function rubriques(): array
    {
        $nomSite = fn (?Site $s) => $s ? ($s->code ? $s->code.' · ' : '').$s->nom : '';
        // Sites soumis et demandes d'autorisation de modification : une seule requête pour les deux rubriques.
        $sites = null;
        $lesSites = function () use (&$sites) {
            return $sites ??= Site::query()->where(fn ($q) => $q->where('statut', StatutSite::Soumis)
                ->orWhere(fn ($r) => $r->where('statut', StatutSite::Confirme)->whereNotNull('revision_demandee_le')))->get();
        };

        return [
            'sites' => ['slug' => 'sites', 'droits' => [Droit::SitesConfirmer], 'groupable' => true, 'elements' => ['site'],
                'source' => fn (\Closure $e) => $lesSites()->where('statut', StatutSite::Soumis)->values()
                    ->map(fn (Site $s) => $e('site:'.$s->id, 'site', $nomSite($s), $s->soumis_par, $s->soumis_le, route('admin.sites.show', $s)))],
            // Données financières : données financières des sites, et moyens de paiement (ajout ou modification, une décision à la fois).
            'finances' => ['slug' => 'finances-sites', 'droits' => [Droit::BoutiquesConfirmer, Droit::MoyensPaiementConfirmer, Droit::TranchesConfirmer], 'groupable' => false,
                'elements' => ['finances', 'moyens', 'investissement', 'delais'],
                'source' => function (\Closure $e, User $user) use ($nomSite) {
                    $liste = collect();
                    if ($user->can(Droit::BoutiquesConfirmer->value)) {
                        $liste = SiteFinance::query()->where('statut', StatutGrilleTarifaire::Soumis)->with('site')->get()
                            ->map(fn (SiteFinance $f) => $e('finances:'.$f->site_id, 'finances', $nomSite($f->site), $f->soumis_par, $f->soumis_le, route('admin.sites.finances', $f->site_id)));
                    }
                    if ($user->can(Droit::TranchesConfirmer->value)) {
                        foreach (ParametresInvestissement::query()->where('statut', StatutGrilleTarifaire::Soumis)->get() as $p) {
                            $liste->push($e('investissement:'.$p->id, 'investissement', __('admin.investissement.titre'), $p->soumis_par, $p->soumis_le, route('admin.investissement')));
                        }
                    }
                    if ($user->can(Droit::MoyensPaiementConfirmer->value)) {
                        foreach (ParametresPaiements::query()->where('statut', StatutGrilleTarifaire::Soumis)->get() as $p) {
                            $liste->push($e('delais:'.$p->id, 'delais', __('admin.delais.titre'), $p->soumis_par, $p->soumis_le, route('admin.parametres-paiements')));
                        }
                        foreach (MoyenPaiement::query()->where(fn ($q) => $q->where('statut', StatutTypeBoutique::AValider)->orWhereNotNull('modification_demandee'))->get() as $m) {
                            $liste->push($e('moyens:'.$m->id, 'moyens', $m->code.' · '.$m->libelle, $m->demande_par, $m->updated_at, route('admin.referencement.zones.show', ['moyens', $m->id])));
                        }
                    }

                    return $liste;
                }],
            'grilles' => ['slug' => 'grilles', 'droits' => [Droit::BoutiquesConfirmer], 'groupable' => true, 'elements' => ['grille'],
                'source' => fn (\Closure $e) => GrilleTarifaire::query()->where('statut', StatutGrilleTarifaire::Soumis)->with('site')->get()
                    ->map(fn (GrilleTarifaire $g) => $e('grille:'.$g->site_id, 'grille', $nomSite($g->site), $g->soumis_par, $g->soumis_le, route('admin.boutiques.grilles.show', $g->site_id)))],
            'referentiels' => ['slug' => 'referentiels', 'droits' => [Droit::SitesConfirmer, Droit::UnitesConfirmer], 'groupable' => true,
                'elements' => ['type', 'niveaux', 'zones', 'unites'],
                'source' => function (\Closure $e, User $user) {
                    $liste = collect();
                    if ($user->can(Droit::SitesConfirmer->value)) {
                        foreach (TypeBoutique::query()->where(fn ($q) => $q->where('statut', StatutTypeBoutique::AValider)->orWhereNotNull('attribuable_demande')->orWhereNotNull('modification_demandee'))->get() as $t) {
                            $liste->push($e('type:'.$t->id, 'type', ($t->code ? $t->code.' · ' : '').$t->libelle, $t->demande_par, $t->demande_le ?? $t->updated_at, route('admin.sites.types.show', $t)));
                        }
                        foreach ([Niveau::class => 'niveaux', ZoneSite::class => 'zones'] as $classe => $type) {
                            foreach ($classe::query()->where(fn ($q) => $q->where('statut', StatutTypeBoutique::AValider)->orWhereNotNull('modification_demandee'))->get() as $r) {
                                $liste->push($e($type.':'.$r->id, $type, $r->code.' · '.$r->libelle, $r->demande_par, $r->updated_at, route('admin.referencement.zones.show', [$type, $r->id])));
                            }
                        }
                    }
                    if ($user->can(Droit::UnitesConfirmer->value)) {
                        foreach (Unite::query()->where(fn ($q) => $q->where('statut', StatutTypeBoutique::AValider)->orWhereNotNull('modification_demandee'))->get() as $u) {
                            $liste->push($e('unites:'.$u->id, 'unites', $u->code.' · '.$u->libelle, $u->demande_par, $u->updated_at, route('admin.referencement.zones.show', ['unites', $u->id])));
                        }
                    }

                    return $liste;
                }],
            'campagnes' => ['slug' => 'campagnes', 'droits' => [Droit::BoutiquesConfirmer], 'groupable' => false, 'elements' => ['campagne'],
                'source' => fn (\Closure $e) => CampagneBoutiques::query()->where('statut', StatutCampagneBoutiques::Soumise)->get()
                    ->map(fn (CampagneBoutiques $c) => $e('campagne:'.$c->id, 'campagne', $c->nom, $c->soumis_par, $c->soumise_le, route('admin.boutiques.show', $c)))],
            'demarches' => ['slug' => 'demarches', 'droits' => [Droit::PhaseConfirmer], 'groupable' => false, 'elements' => ['phase'],
                'source' => fn (\Closure $e) => DecisionPhase::query()->where('statut', DecisionPhase::SOUMISE)->get()
                    ->map(fn (DecisionPhase $d) => $e('phase:'.$d->id, 'phase', self::libellePhase($d), $d->propose_par, $d->created_at, route('admin.phase')))],
            'autorisations' => ['slug' => 'autorisations-sites', 'droits' => [Droit::SitesConfirmer], 'groupable' => true, 'elements' => ['autorisation'],
                'source' => fn (\Closure $e) => $lesSites()->where('statut', StatutSite::Confirme)->whereNotNull('revision_demandee_le')->values()
                    ->map(fn (Site $s) => $e('autorisation:'.$s->id, 'autorisation', $nomSite($s).' : « '.$s->revision_demande_motif.' »', $s->revision_demandee_par, $s->revision_demandee_le, route('admin.sites.show', $s)))],
            // Paiements : corrections de paiements confirmés et exécutions de remboursements déclarées par le CONTRÔLEUR DES PAIEMENTS.
            'paiements' => ['slug' => 'paiements', 'droits' => [Droit::PaiementsCorrectionsConfirmer, Droit::RemboursementsApprouver], 'groupable' => false,
                'elements' => ['correction', 'remboursement'],
                'source' => function (\Closure $e, User $user) {
                    $liste = collect();
                    if ($user->can(Droit::PaiementsCorrectionsConfirmer->value)) {
                        foreach (CorrectionPaiement::query()->where('statut', CorrectionPaiement::PROPOSEE)->with('paiement')->get() as $c) {
                            $p = $c->paiement;
                            $liste->push($e('correction:'.$c->id, 'correction', $p->reference.' · '.$p->objet()->reference, $c->propose_par, $c->propose_le,
                                route('admin.paiements.dossier', $p->souscription_id ? ['souscription', $p->souscription_id] : ['caution', $p->reservation_boutique_id])));
                        }
                    }
                    if ($user->can(Droit::RemboursementsApprouver->value)) {
                        foreach (Remboursement::query()->where('statut', Remboursement::PROPOSE)->get() as $r) {
                            $liste->push($e('remboursement:'.$r->id, 'remboursement', $r->reference, $r->propose_par, $r->propose_le, route('admin.remboursements')));
                        }
                    }

                    return $liste;
                }],
            // Avances en retard : prolongations et annulations proposées par le RÉGULATEUR (une décision à la fois).
            'avances' => ['slug' => 'avances', 'droits' => [Droit::AvancesDecider], 'groupable' => false, 'elements' => ['avance'],
                'source' => fn (\Closure $e) => DecisionAvance::query()->where('statut', DecisionAvance::PROPOSEE)->with('reservation.boutique')->get()
                    ->map(fn (DecisionAvance $d) => $e('avance:'.$d->id, 'avance', $d->reservation->reference.' · '.$d->reservation->boutique?->code.' · '.$d->resume(),
                        $d->propose_par, $d->propose_le, route('admin.avances')))],
            // Identités : annulations proposées par l'ADMIN pour un dossier douteux (une décision à la fois).
            'identites' => ['slug' => 'identites', 'droits' => [Droit::KycAnnulationConfirmer], 'groupable' => false, 'elements' => ['annulation'],
                'source' => fn (\Closure $e) => VerificationKyc::query()->where('statut', VerificationKyc::ANNULATION_PROPOSEE)->with('user')->get()
                    ->map(fn (VerificationKyc $v) => $e('annulation:'.$v->id, 'annulation', $v->user->name, $v->decide_par, $v->created_at, route('admin.kyc.dossier', $v->user_id)))],
            // Import entier soumis : une ligne par import qui a encore des sites soumis ; jamais plusieurs imports à la fois.
            'imports' => ['slug' => 'imports', 'droits' => [Droit::SitesConfirmer], 'groupable' => false, 'elements' => ['import'],
                'source' => fn (\Closure $e) => ImportValidation::query()->enAttente()->with('sites.site')->latest('id')->get()
                    ->map(fn (ImportValidation $i) => $e('import:'.$i->id, 'import', $i->fichier.' · '.trans_choice('admin.imports.n_sites', $i->sitesEnAttente()->count())
                        .' ('.$i->sitesEnAttente()->map(fn ($l) => $l->site->code)->implode(', ').')', $i->auteur_id, $i->created_at, route('admin.imports-validation.show', $i)))],
        ];
    }

    /** Types d'éléments sensibles : jamais de décision groupée (cases à cocher masquées, refus 422 côté serveur). @return list<string> */
    public static function nonGroupables(): array
    {
        return collect(self::rubriques())->reject(fn (array $r) => $r['groupable'])->pluck('elements')->flatten()->values()->all();
    }

    /** Groupe d'une adresse de rubrique (« finances-sites » → « finances »), ou null. */
    public static function groupeDuSlug(string $slug): ?string
    {
        return collect(self::rubriques())->search(fn (array $r) => $r['slug'] === $slug) ?: null;
    }

    /**
     * Éléments en attente par rubrique visible pour l'utilisateur (les plus anciens d'abord).
     *
     * @return array<string, list<array{cle: string, element: string, libelle: string, auteur_id: ?int, par: ?string, date: ?CarbonInterface, url: string, moi: bool, groupable: bool}>>
     */
    public static function pour(User $user): array
    {
        // Une seule fois par page (menu, badges, tableau de bord, page « À valider ») : jamais entre deux requêtes ni
        // pendant une décision (POST), pour rester juste.
        return MemoRequete::retenir('a_valider.'.$user->id, fn () => self::calculer($user));
    }

    /** @return array<string, list<array>> */
    private static function calculer(User $user): array
    {
        $e = fn (string $cle, string $element, string $libelle, ?int $auteur, ?CarbonInterface $date, string $url) => self::element($user, $cle, $element, $libelle, $auteur, $date, $url);
        $g = [];
        foreach (self::rubriques() as $groupe => $rubrique) {
            if (in_array($groupe, self::groupesVisibles($user), true)) {
                $g[$groupe] = collect(($rubrique['source'])($e, $user))->sortBy(fn ($el) => $el['date']?->timestamp ?? 0)->values()->all();
            }
        }

        // Noms des auteurs : une seule requête pour toute la file.
        $noms = User::query()->whereIn('id', collect($g)->flatten(1)->pluck('auteur_id')->filter()->unique()->all())->pluck('name', 'id');

        return array_map(fn (array $elements) => array_map(fn (array $el) => [...$el, 'par' => $noms[$el['auteur_id']] ?? null], $elements), $g);
    }

    /** Nombre d'éléments en attente par type d'élément (site, type, niveaux…), y compris ceux soumis par l'utilisateur. @return array<string, int> */
    public static function parElement(User $user): array
    {
        return collect(self::pour($user))->flatten(1)->countBy('element')->all();
    }

    /** Nombre d'éléments sur lesquels l'utilisateur peut décider (badge du menu). */
    public static function total(User $user): int
    {
        return collect(self::pour($user))->flatten(1)->where('moi', false)->count();
    }

    /** Compteur de chaque rubrique (éléments sur lesquels l'utilisateur peut décider). @return array<string, int> */
    public static function compteurs(User $user): array
    {
        return array_map(fn (array $elements) => collect($elements)->where('moi', false)->count(), self::pour($user));
    }

    /** Élément encore en attente, ou null (déjà traité, inconnu ou hors des droits de l'utilisateur). */
    public static function trouver(User $user, string $cle): ?array
    {
        return collect(self::pour($user))->flatten(1)->firstWhere('cle', $cle);
    }

    /** Rubriques visibles pour ce profil (celles dont il détient un droit de décision). @return list<string> */
    public static function groupesVisibles(User $user): array
    {
        return array_keys(array_filter(self::rubriques(), fn (array $r) => collect($r['droits'])->contains(fn (Droit $d) => $user->can($d->value))));
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
            'delais' => (function () use ($id) {
                $p = ParametresPaiements::query()->findOrFail($id);

                return [...self::parametrable($p, __('admin.delais.champs'), true), 'infos' => [__('admin.parametrage.statut') => $p->statut->label()]];
            })(),
            'investissement' => (function () use ($id) {
                $p = ParametresInvestissement::query()->findOrFail($id);

                return [...self::parametrable($p, __('admin.investissement.champs'), true), 'infos' => [__('admin.parametrage.statut') => $p->statut->label()]];
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

                return [...self::referentiel($versions, __('admin.types.champs'), $t), 'infos' => [
                    __('admin.a_valider.detail.code') => $t->code, __('admin.a_valider.detail.libelle') => $t->libelle,
                    __('admin.a_valider.detail.description') => $t->description, __('admin.parametrage.statut') => $t->statut->label(),
                ]];
            })(),
            'niveaux', 'zones', 'unites', 'moyens' => (function () use ($id, $type) {
                $r = ['niveaux' => Niveau::class, 'zones' => ZoneSite::class, 'unites' => Unite::class, 'moyens' => MoyenPaiement::class][$type]::query()->findOrFail($id);

                return [...self::referentiel($r->versions(), __('admin.zones.colonnes'), $r), 'infos' => [
                    __('admin.a_valider.detail.code') => $r->code, __('admin.a_valider.detail.libelle') => $r->libelle,
                    __('admin.a_valider.detail.description') => $r->description ?? null, __('admin.parametrage.statut') => $r->statut->label(),
                ]];
            })(),
            'import' => (function () use ($id) {
                $i = ImportValidation::query()->with(['sites.site', 'auteur'])->findOrFail($id);
                $x = __('admin.imports_validation');

                return ['infos' => [
                    $x['infos']['type'] => $x['entites'][$i->entite], $x['infos']['fichier'] => $i->fichier, $x['infos']['contenu'] => ImportValidationPresentation::contenu($i),
                    $x['infos']['sites'] => $i->sites->map(fn ($l) => $l->site->code)->implode(', '), $x['infos']['statut'] => $x['statuts'][$i->statutValidation()],
                ], 'pieces' => [
                    ...($i->chemin ? [['libelle' => $x['telecharger'], 'url' => route('admin.imports-validation.fichier', $i)]] : []),
                    ...$i->sites->map(fn ($l) => ['libelle' => ($l->site->code ? $l->site->code.' · ' : '').$l->site->nom, 'url' => route('admin.sites.show', $l->site)])->all(),
                ]];
            })(),
            'avance' => (function () use ($id) {
                $d = DecisionAvance::query()->with(['reservation.boutique', 'reservation.user', 'reservation.paiements'])->findOrFail($id);
                $r = $d->reservation;
                $x = __('admin.avances.detail');
                $caution = $r->paiements->where('nature', 'CAUTION')->where('statut', StatutPaiement::Confirme)->sortByDesc('id')->first();

                return ['infos' => [
                    $x['reservation'] => $r->reference, $x['boutique'] => $r->boutique?->code, $x['citoyen'] => $r->user?->name,
                    $x['date_limite'] => $r->avance_due_le?->format('d/m/Y H:i'), $x['retard_depuis'] => $r->avance_retard_le?->format('d/m/Y H:i'),
                    $x['avance'] => format_fcfa((int) $r->avance), $x['caution'] => format_fcfa((int) $r->caution),
                    $x['decision'] => $d->resume(), $x['motif'] => $d->motif,
                    ...($d->decision === DecisionAvance::ANNULER ? [
                        $x['retenue'] => format_nombre((int) $d->retenue_pct).' %', $x['rembourse'] => format_fcfa($d->montantRembourse()),
                        $x['paiement_caution'] => $caution?->reference,
                    ] : []),
                    $x['effet'] => $d->decision === DecisionAvance::PROLONGER ? $x['effet_prolonger'] : $x['effet_annuler'],
                ]];
            })(),
            'correction' => (function () use ($id) {
                $c = CorrectionPaiement::query()->with(['paiement.controleur'])->findOrFail($id);
                $p = $c->paiement;
                $x = __('admin.paiements.detail');

                return ['infos' => [
                    $x['paiement'] => $p->reference, $x['objet'] => $p->objet()->reference, $x['citoyen'] => $p->objet()->user?->name,
                    $x['canal'] => $p->canal?->libelle, $x['montant'] => format_fcfa($p->montant + $p->frais),
                    $x['confirme_le'] => $p->date_confirmation?->format('d/m/Y H:i'),
                    $x['controle'] => $p->controle ? __('admin.paiements.controles.'.$p->controle).($p->controleur ? ' ('.$p->controleur->name.')' : '') : null,
                    $x['motif_correction'] => $c->motif, $x['effet'] => $x['effet_correction'],
                ]];
            })(),
            'remboursement' => (function () use ($id) {
                $r = Remboursement::query()->with(['user'])->findOrFail($id);
                $x = __('admin.remboursements.detail');

                return ['infos' => [
                    $x['reference'] => $r->reference, $x['objet'] => $r->objet()?->reference, $x['citoyen'] => $r->user?->name,
                    $x['montant'] => format_fcfa($r->montant), $x['origine'] => __('admin.remboursements.origines.'.$r->origine),
                    $x['mode'] => $r->mode, $x['reference_operation'] => $r->reference_operation,
                ]];
            })(),
            'annulation' => (function () use ($id) {
                $v = VerificationKyc::query()->with(['user', 'auteur'])->findOrFail($id);
                $x = __('admin.kyc.detail');
                $citoyen = $v->user;

                return ['infos' => [
                    $x['citoyen'] => $citoyen->name, $x['telephone'] => $citoyen->telephone, $x['motif'] => $v->motif,
                    $x['souscriptions'] => format_nombre($citoyen->souscriptions()->whereIn('statut', ['RESERVEE', 'PAYEE'])->count()),
                    $x['candidatures'] => format_nombre(ReservationBoutique::query()->where('user_id', $citoyen->id)->whereIn('statut', StatutReservationBoutique::valeursActives())->count()),
                    $x['effet'] => $x['effet_annulation'],
                ], 'pieces' => app(VerificationIdentite::class)->dossier($citoyen)->filter()
                    ->map(fn ($d) => ['libelle' => __('admin.kyc.pieces.'.strtolower($d->type->value)).($d->face ? ' · '.$d->face->value : ''), 'url' => route('admin.kyc.piece', $d)])->values()->all()];
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

        // Commentaire laissé au VALIDATEUR lors d'une soumission groupée depuis une liste.
        $commentaire = AuditLog::query()->where('action', 'soumission.commentaire')->where('entite_type', $type)->where('entite_id', $id)
            ->when($element['date'] ?? null, fn ($q, $date) => $q->where('created_at', '>=', $date->copy()->subMinute()))->latest('id')->value('nouvelle_valeur');
        if (! empty($commentaire['commentaire'])) {
            $d['infos'] = [...($d['infos'] ?? []), __('admin.soumission.commentaire_detail') => $commentaire['commentaire']];
        }

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

    /**
     * Versions figées d'un référentiel : différences entre la version soumise et la précédente ; pour une modification
     * demandée sur un élément « par défaut », entre les valeurs en vigueur et les valeurs demandées.
     */
    private static function referentiel(Collection $versions, array $libelles, ?Model $objet = null): array
    {
        $versions = $versions->sortByDesc('numero')->values();
        if ($objet?->modification_demandee) {
            $differentiel = collect($objet->modification_demandee)->map(fn ($v, $k) => [$objet->getAttribute($k), $v])->all();

            return ['differentiel' => $differentiel, 'libelles' => [...$libelles, 'actif' => __('admin.a_valider.detail.actif')], 'premiere' => false, 'versions' => $versions, 'decisions' => collect()];
        }
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

    /**
     * Fiches que seul l'onglet de l'auteur ouvre (Référentiels, Modalités de paiement, Unités) : le VALIDATEUR décide depuis
     * le panneau de détail (informations, différences, historique), sans lien vers une fiche qui lui serait refusée.
     */
    private const FICHES = ['type' => Droit::ReferentielsBoutiquesVoir, 'niveaux' => Droit::ReferentielsBoutiquesVoir, 'zones' => Droit::ReferentielsBoutiquesVoir,
        'moyens' => Droit::ModalitesPaiementVoir, 'delais' => Droit::ModalitesPaiementVoir, 'unites' => Droit::UnitesVoir];

    /**
     * « url » : la fiche de l'élément, ou, quand elle est refusée au profil, son panneau de détail dans « À valider »
     * (lien « Examiner » du tableau de bord) ; « fiche » : le bouton « Ouvrir la fiche » est proposé.
     *
     * @return array{cle: string, element: string, libelle: string, auteur_id: ?int, par: ?string, date: ?CarbonInterface, url: string, fiche: bool, moi: bool, groupable: bool}
     */
    private static function element(User $user, string $cle, string $element, string $libelle, ?int $auteur, ?CarbonInterface $date, string $url): array
    {
        $fiche = ! isset(self::FICHES[$element]) || $user->can(self::FICHES[$element]->value);

        return [
            'cle' => $cle, 'element' => $element, 'libelle' => $libelle, 'auteur_id' => $auteur,
            'par' => null, 'date' => $date, 'url' => $fiche ? $url : route('admin.a-valider', ['detail' => $cle]), 'fiche' => $fiche, // nom de l'auteur : chargé ensuite pour toute la file
            // Quatre yeux : jamais de décision sur un élément que l'on a soumis soi-même.
            'moi' => $auteur !== null && $auteur === $user->id,
            'groupable' => ! in_array($element, self::nonGroupables(), true),
        ];
    }
}
