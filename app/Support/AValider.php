<?php

namespace App\Support;

use App\Enums\Droit;
use App\Enums\StatutCampagneBoutiques;
use App\Enums\StatutGrilleTarifaire;
use App\Enums\StatutSite;
use App\Enums\StatutTypeBoutique;
use App\Models\CampagneBoutiques;
use App\Models\DecisionPhase;
use App\Models\GrilleTarifaire;
use App\Models\Niveau;
use App\Models\Site;
use App\Models\SiteFinance;
use App\Models\TypeBoutique;
use App\Models\Unite;
use App\Models\User;
use App\Models\ZoneSite;
use Carbon\CarbonInterface;

/**
 * File « À valider » du VALIDATEUR : tout ce qui attend sa décision, regroupé par type d'élément (jamais ce qu'il a
 * soumis lui-même). Chaque élément porte une clé « type:identifiant » utilisée par les décisions (une ou plusieurs).
 */
final class AValider
{
    /** Ordre des groupes. */
    public const GROUPES = ['sites', 'grilles', 'finances', 'referentiels', 'campagnes', 'demarches', 'autorisations'];

    /**
     * @return array<string, list<array{cle: string, element: string, libelle: string, par: ?string, date: ?CarbonInterface, url: string}>>
     */
    public static function pour(User $user): array
    {
        $g = array_fill_keys(self::GROUPES, []);
        $nomSite = fn (?Site $s) => $s ? ($s->code ? $s->code.' · ' : '').$s->nom : '';

        if ($user->can(Droit::SitesConfirmer->value)) {
            foreach (Site::query()->where('statut', StatutSite::Soumis)->orderBy('soumis_le')->get() as $s) {
                $g['sites'][] = self::element('site:'.$s->id, 'site', $nomSite($s), $s->soumis_par, $s->soumis_le, route('admin.sites.show', $s));
            }
            foreach (TypeBoutique::query()->where(fn ($q) => $q->where('statut', StatutTypeBoutique::AValider)->orWhereNotNull('attribuable_demande'))->get() as $t) {
                $g['referentiels'][] = self::element('type:'.$t->id, 'type', ($t->code ? $t->code.' · ' : '').$t->libelle, $t->demande_par, $t->demande_le ?? $t->updated_at, route('admin.sites.types.show', $t));
            }
            foreach ([Niveau::class => 'niveaux', ZoneSite::class => 'zones'] as $classe => $liste) {
                foreach ($classe::query()->where('statut', StatutTypeBoutique::AValider)->get() as $r) {
                    $g['referentiels'][] = self::element($liste.':'.$r->id, $liste, $r->code.' · '.$r->libelle, $r->demande_par, $r->updated_at, route('admin.referencement.zones.show', [$liste, $r->id]));
                }
            }
            foreach (Site::query()->where('statut', StatutSite::Confirme)->whereNotNull('revision_demandee_le')->orderBy('revision_demandee_le')->get() as $s) {
                $g['autorisations'][] = self::element('autorisation:'.$s->id, 'autorisation', $nomSite($s).' : « '.$s->revision_demande_motif.' »', $s->revision_demandee_par, $s->revision_demandee_le, route('admin.sites.show', $s));
            }
        }
        if ($user->can(Droit::UnitesConfirmer->value)) {
            foreach (Unite::query()->where('statut', StatutTypeBoutique::AValider)->get() as $u) {
                $g['referentiels'][] = self::element('unites:'.$u->id, 'unites', $u->code.' · '.$u->libelle, $u->demande_par, $u->updated_at, route('admin.referencement.zones.show', ['unites', $u->id]));
            }
        }
        if ($user->can(Droit::BoutiquesConfirmer->value)) {
            foreach (GrilleTarifaire::query()->where('statut', StatutGrilleTarifaire::Soumis)->where('soumis_par', '!=', $user->id)->with('site')->get() as $gr) {
                $g['grilles'][] = self::element('grille:'.$gr->site_id, 'grille', $nomSite($gr->site), $gr->soumis_par, $gr->soumis_le, route('admin.boutiques.grilles.show', $gr->site_id));
            }
            foreach (SiteFinance::query()->where('statut', StatutGrilleTarifaire::Soumis)->where('soumis_par', '!=', $user->id)->with('site')->get() as $f) {
                $g['finances'][] = self::element('finances:'.$f->site_id, 'finances', $nomSite($f->site), $f->soumis_par, $f->soumis_le, route('admin.sites.finances', $f->site_id));
            }
            foreach (CampagneBoutiques::query()->where('statut', StatutCampagneBoutiques::Soumise)->where('soumis_par', '!=', $user->id)->get() as $c) {
                $g['campagnes'][] = self::element('campagne:'.$c->id, 'campagne', $c->nom, $c->soumis_par, $c->soumise_le, route('admin.boutiques.show', $c));
            }
        }
        if ($user->can(Droit::PhaseConfirmer->value)) {
            foreach (DecisionPhase::query()->where('statut', DecisionPhase::SOUMISE)->where('propose_par', '!=', $user->id)->get() as $d) {
                $libelle = __('admin.phase.parametres.presentation_actions').' : '.__('admin.phase.'.($d->presentation_actions ? 'oui' : 'non'))
                    .' · '.__('admin.phase.parametres.souscription_actions').' : '.__('admin.phase.'.($d->souscription_actions ? 'oui' : 'non'));
                $g['demarches'][] = self::element('phase:'.$d->id, 'phase', $libelle, $d->propose_par, $d->created_at, route('admin.phase'));
            }
        }

        foreach ($g as &$elements) {
            usort($elements, fn ($a, $b) => ($a['date']?->timestamp ?? 0) <=> ($b['date']?->timestamp ?? 0));
        }

        return $g;
    }

    /** Nombre total d'éléments à valider (badge du menu). */
    public static function total(User $user): int
    {
        return array_sum(array_map('count', self::pour($user)));
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

    /** @return array{cle: string, element: string, libelle: string, par: ?string, date: ?CarbonInterface, url: string} */
    private static function element(string $cle, string $element, string $libelle, ?int $par, ?CarbonInterface $date, string $url): array
    {
        return ['cle' => $cle, 'element' => $element, 'libelle' => $libelle, 'par' => $par ? User::query()->whereKey($par)->value('name') : null, 'date' => $date, 'url' => $url];
    }
}
