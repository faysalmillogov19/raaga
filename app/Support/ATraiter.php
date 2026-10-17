<?php

namespace App\Support;

use App\Enums\Droit;
use App\Enums\StatutAppelFonds;
use App\Enums\StatutAvenant;
use App\Enums\StatutConvention;
use App\Enums\StatutDeclarationVirement;
use App\Enums\StatutDistribution;
use App\Enums\StatutDocumentKyc;
use App\Enums\StatutGrilleTarifaire;
use App\Enums\StatutKyc;
use App\Enums\StatutReservationBoutique;
use App\Enums\StatutSite;
use App\Enums\StatutTranche;
use App\Enums\StatutTypeBoutique;
use App\Models\AppelFonds;
use App\Models\Avenant;
use App\Models\Convention;
use App\Models\DeclarationVirement;
use App\Models\DistributionDividende;
use App\Models\GrilleTarifaire;
use App\Models\Institution;
use App\Models\Niveau;
use App\Models\ReservationBoutique;
use App\Models\Site;
use App\Models\SiteFinance;
use App\Models\Tranche;
use App\Models\TypeBoutique;
use App\Models\User;
use App\Models\ZoneSite;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Bloc « À traiter » du tableau de bord, selon les droits du profil :
 *  - qui saisit (RÉGULATEUR, GESTIONNAIRE DES SITES ET BOUTIQUES) : ce qui a été rejeté et doit être repris ;
 *  - qui décide (VALIDATEUR) : ce qui a été soumis et attend sa décision (jamais ce qu'il a saisi lui-même).
 * Chaque élément : Élément, Objet, Soumis par, Date, Statut, adresse (bouton « Examiner »), du plus ancien au plus récent.
 */
class ATraiter
{
    /** Durée d'affichage d'un avenant rejeté chez le régulateur. */
    private const JOURS_AVENANT_REJETE = 30;

    /** Nombre maximal de dossiers KYC listés (les plus anciens). */
    private const KYC_MAX = 20;

    /**
     * @return list<array{libelle: string, detail: ?string, url: string, element: string, objet: string, par: ?string, date: ?CarbonInterface, statut: string}>
     */
    public static function pour(User $user): array
    {
        // Une seule fois par page (cloche du cadre, tableau de bord) ; recalculé à chaque requête.
        return MemoRequete::retenir('a_traiter.'.$user->id, fn () => self::calculer($user));
    }

    /** @return list<array> */
    private static function calculer(User $user): array
    {
        $e = [];
        // VALIDATEUR : sites, référentiels, grilles, données financières et campagnes soumis viennent de la file
        // « À valider », déjà calculée pour la page (aucune requête de plus).
        $file = $user->can(Droit::AValiderVoir->value) ? collect(AValider::pour($user))->flatten(1) : collect();
        $depuisFile = function (array $elements, array $statuts, bool $sansMoi) use ($file, &$e) {
            foreach ($file->whereIn('element', array_keys($elements)) as $el) {
                if (! ($sansMoi && $el['moi'])) {
                    $e[] = self::element($elements[$el['element']], $el['libelle'], $el['auteur_id'], $el['date'], $statuts[$el['element']], $el['url']);
                }
            }
        };

        // ------------------------------------------------------------------ Référencement
        if ($user->can(Droit::SitesConfirmer->value)) {
            $depuisFile(['site' => 'site', 'type' => 'type', 'niveaux' => 'niveau', 'zones' => 'zone'],
                ['site' => 'Soumis', 'type' => 'Soumis', 'niveaux' => 'Soumise', 'zones' => 'Soumise'], false);
        }
        if ($user->can(Droit::SitesGerer->value)) {
            foreach (Site::query()->where('statut', StatutSite::Brouillon)->whereNotNull('motif_rejet')->get() as $s) {
                $e[] = self::element('site', self::nomSite($s), $s->valide_par ?? null, $s->updated_at, 'Rejeté', route('admin.sites.show', $s), $s->motif_rejet);
            }
            foreach (TypeBoutique::query()->where('statut', StatutTypeBoutique::Rejete)->get() as $t) {
                $e[] = self::element('type', ($t->code ? $t->code.' · ' : '').$t->libelle, $t->valide_par, $t->updated_at, 'Rejeté', route('admin.sites.types.show', $t), $t->motif_rejet);
            }
            foreach ([Niveau::class => 'niveaux', ZoneSite::class => 'zones'] as $classe => $liste) {
                foreach ($classe::query()->where('statut', StatutTypeBoutique::Rejete)->get() as $r) {
                    $e[] = self::element($liste === 'niveaux' ? 'niveau' : 'zone', $r->code.' · '.$r->libelle, $r->valide_par, $r->updated_at, 'Rejetée', route('admin.referencement.zones.show', [$liste, $r->id]), $r->motif_rejet);
                }
            }
        }

        // ------------------------------------------------------------------ Grilles, données financières, campagnes
        if ($user->can(Droit::BoutiquesConfirmer->value)) {
            // Jamais ce que le validateur a saisi lui-même.
            $depuisFile(['grille' => 'grille', 'finances' => 'finances', 'campagne' => 'campagne'],
                ['grille' => 'Soumise', 'finances' => 'Soumises', 'campagne' => 'Soumise'], true);
        }
        if ($user->can(Droit::BoutiquesSaisir->value)) {
            foreach (GrilleTarifaire::query()->where('statut', StatutGrilleTarifaire::Brouillon)->whereNotNull('motif_rejet')->with('site')->get() as $g) {
                $e[] = self::element('grille', self::nomSite($g->site), $g->valide_par, $g->updated_at, 'Rejetée', route('admin.boutiques.grilles.show', $g->site_id), $g->motif_rejet);
            }
            foreach (SiteFinance::query()->where('statut', StatutGrilleTarifaire::Brouillon)->whereNotNull('motif_rejet')->with('site')->get() as $f) {
                $e[] = self::element('finances', self::nomSite($f->site), $f->valide_par, $f->updated_at, 'Rejetées', route('admin.sites.finances', $f->site_id), $f->motif_rejet);
            }
        }

        // ------------------------------------------------------------------ Tranches et dividendes
        if ($user->can(Droit::TranchesSaisir->value)) {
            foreach (Tranche::query()->where('statut', StatutTranche::Brouillon)->whereNotNull('motif_rejet')->get() as $t) {
                $e[] = self::element('tranche', $t->nom, $t->valide_par ?? null, $t->updated_at, 'Rejetée', route('admin.tranches.show', $t), $t->motif_rejet);
            }
            $e = [...$e, ...self::avenantsRejetes(Tranche::class, $user)];
        }
        if ($user->can(Droit::DividendesSaisir->value)) {
            foreach (DistributionDividende::query()->where('statut', StatutDistribution::Brouillon)->whereNotNull('motif_rejet')->get() as $d) {
                $e[] = self::element('distribution', __('admin.dividendes.exercice', ['annee' => $d->exercice]), $d->valide_par ?? null, $d->updated_at, 'Rejetée', route('admin.dividendes.show', $d), $d->motif_rejet);
            }
            $e = [...$e, ...self::avenantsRejetes(DistributionDividende::class, $user)];
        }
        if ($user->can(Droit::TranchesConfirmer->value)) {
            foreach (Tranche::query()->where('statut', StatutTranche::Soumise)->get() as $t) {
                $e[] = self::element('tranche', $t->nom.' · v'.$t->version, $t->soumis_par, $t->soumise_le, 'Soumise', route('admin.tranches.show', $t));
            }
            $e = [...$e, ...self::avenantsSoumis(Tranche::class, $user)];
        }
        if ($user->can(Droit::DividendesConfirmer->value)) {
            foreach (DistributionDividende::query()->where('statut', StatutDistribution::Soumis)->get() as $d) {
                $e[] = self::element('distribution', __('admin.dividendes.exercice', ['annee' => $d->exercice]).' · v'.$d->version, $d->soumis_par, $d->soumise_le, 'Soumise', route('admin.dividendes.show', $d));
            }
            $e = [...$e, ...self::avenantsSoumis(DistributionDividende::class, $user)];
        }

        // ------------------------------------------------------------------ Actions A
        if ($user->can(Droit::ConventionsSaisir->value)) {
            foreach (Convention::query()->where('statut', StatutConvention::Brouillon)->whereNotNull('motif_rejet')->get() as $c) {
                $e[] = self::element('convention', $c->reference, $c->valide_par ?? null, $c->updated_at, 'Rejetée', route('admin.conventions.show', $c), $c->motif_rejet);
            }
            $e = [...$e, ...self::avenantsRejetes(Convention::class, $user)];
        }
        if ($user->can(Droit::ConventionsConfirmer->value)) {
            // Conventions soumises et signatures déposées : une seule requête.
            $conventions = Convention::query()->where(fn ($q) => $q->where('statut', StatutConvention::Soumise)
                ->orWhere(fn ($r) => $r->whereNull('signee_le')->whereNotNull('signature_deposee_le')))->get();
            foreach ($conventions->where('statut', StatutConvention::Soumise) as $c) {
                $e[] = self::element('convention', $c->reference.' · v'.$c->version, $c->soumis_par, $c->soumise_le, 'Soumise', route('admin.conventions.show', $c));
            }
            foreach ($conventions->whereNull('signee_le')->whereNotNull('signature_deposee_le') as $c) {
                $e[] = self::element('convention_deposee', $c->reference, null, $c->signature_deposee_le, 'En attente', route('admin.conventions.show', $c));
            }
            $e = [...$e, ...self::avenantsSoumis(Convention::class, $user)];
        }
        if ($user->can(Droit::KybVerifier->value)) {
            foreach (Institution::query()->whereHas('documents', fn ($q) => $q->where('statut', StatutDocumentKyc::EnAttente))->get() as $i) {
                $n = $i->piecesEnVigueur()->where('statut', StatutDocumentKyc::EnAttente)->count();
                if ($n > 0) {
                    $e[] = self::element('kyb', __('admin.tableau.a_traiter.kyb_objet', ['institution' => $i->nomCourt(), 'n' => $n]), null, $i->updated_at, 'En attente', route('admin.kyb.show', $i));
                }
            }
        }
        if ($user->can(Droit::AppelsFondsRapprocher->value)) {
            foreach (DeclarationVirement::query()->where('statut', StatutDeclarationVirement::Declaree)->with('appel.institution')->get() as $d) {
                $e[] = self::element('virement', $d->appel->institution->nomCourt().' · '.format_fcfa($d->montant), null, $d->created_at, 'À rapprocher', route('admin.appels-fonds.show', $d->appel_fonds_id));
            }
            foreach (AppelFonds::query()->where('statut', StatutAppelFonds::EnRetard)->with('institution')->get() as $a) {
                $e[] = self::element('appel', $a->institution->nomCourt().' · n° '.$a->numero, null, $a->echeance, 'En retard', route('admin.appels-fonds.show', $a));
            }
        }

        // ------------------------------------------------------------------ Dossiers KYC des citoyens
        if ($user->can(Droit::KycTraiter->value)) {
            // Commerçants déjà attribués : leur carte attend ce dossier, ils passent en tête (cf. tri ci-dessous).
            $attribues = ReservationBoutique::query()->select('user_id')->where('statut', StatutReservationBoutique::Attribuee);
            foreach (User::query()->where('statut_kyc', StatutKyc::EnAttente)->whereIn('id', $attribues)->oldest('updated_at')->limit(self::KYC_MAX)->get() as $c) {
                $e[] = [...self::element('kyc_commercant', 'CIT-'.$c->updated_at->format('Y').'-'.str_pad((string) $c->id, 5, '0', STR_PAD_LEFT), __('admin.tableau.a_traiter.citoyen'),
                    $c->updated_at, __('admin.tableau.a_traiter.prioritaire'), route('admin.kyc.dossier', $c)), 'prioritaire' => true];
            }
            // Dossiers anciens « pièces à mettre à jour » : signalés en une ligne (le titulaire doit déposer un dossier complet).
            $aMettreAJour = User::query()->where('statut_kyc', StatutKyc::AMettreAJour)->selectRaw('count(*) as n, min(kyc_maj_demandee_le) as depuis')->first();
            if ($aMettreAJour?->n) {
                $e[] = self::element('kyc_a_mettre_a_jour', trans_choice('admin.tableau.a_traiter.dossiers_n', (int) $aMettreAJour->n, ['n' => $aMettreAJour->n]), null,
                    $aMettreAJour->depuis ? Carbon::parse($aMettreAJour->depuis) : null,
                    __('admin.tableau.a_traiter.attente_titulaire'), route('admin.kyc', ['statut_kyc' => StatutKyc::AMettreAJour->value]));
            }
            foreach (User::query()->where('statut_kyc', StatutKyc::EnAttente)->whereNotIn('id', $attribues)->oldest('updated_at')->limit(self::KYC_MAX)->get() as $c) {
                $heures = (int) $c->updated_at->diffInHours(now());
                $e[] = self::element('kyc', 'CIT-'.$c->updated_at->format('Y').'-'.str_pad((string) $c->id, 5, '0', STR_PAD_LEFT), __('admin.tableau.a_traiter.citoyen'),
                    $c->updated_at, __('admin.tableau.a_traiter.en_attente_depuis', ['h' => $heures]), route('admin.kyc'));
            }
        }

        // Prioritaires d'abord (dossiers KYC des commerçants attribués), puis du plus ancien au plus récent.
        usort($e, fn ($a, $b) => [! ($a['prioritaire'] ?? false), $a['date']?->getTimestamp() ?? 0] <=> [! ($b['prioritaire'] ?? false), $b['date']?->getTimestamp() ?? 0]);

        // Profil de chaque auteur : une seule requête pour tout le bloc.
        $auteurs = User::query()->whereIn('id', array_filter(array_column($e, 'par'), 'is_int'))->get()->keyBy('id');

        return array_map(fn (array $el) => [...$el, 'par' => is_int($el['par']) ? $auteurs[$el['par']]?->codeRole()->label() : $el['par']], $e);
    }

    /** @param class-string $type */
    private static function avenantsRejetes(string $type, User $user): array
    {
        // Avenants rejetés récents de tous les types : une seule requête pour le bloc.
        $tous = MemoRequete::retenir('a_traiter.avenants_rejetes', fn () => Avenant::query()->where('statut', StatutAvenant::Rejete)
            ->where('decide_le', '>=', now()->subDays(self::JOURS_AVENANT_REJETE))->with('avenantable')->latest('id')->get());

        return $tous->where('avenantable_type', $type)
            ->map(fn (Avenant $a) => self::element('avenant', self::nomObjet($a), $a->decide_par ?? null, $a->decide_le, 'Rejeté', self::url($a), $a->motif_decision))
            ->all();
    }

    /**
     * Avenants en attente, sauf ceux que le validateur aurait lui-même saisis (quatre yeux).
     *
     * @param  class-string  $type
     */
    private static function avenantsSoumis(string $type, User $user): array
    {
        // Avenants en attente de tous les types : une seule requête pour le bloc.
        $tous = MemoRequete::retenir('a_traiter.avenants_soumis.'.$user->id, fn () => Avenant::query()->where('statut', StatutAvenant::EnAttente)
            ->where('saisi_par', '!=', $user->id)->with('avenantable')->oldest('id')->get());

        return $tous->where('avenantable_type', $type)
            ->map(fn (Avenant $a) => self::element('avenant', self::nomObjet($a), $a->saisi_par, $a->created_at, 'Soumis', self::url($a), $a->motif))
            ->all();
    }

    private static function nomSite(?Model $site): string
    {
        return $site ? ($site->code ? $site->code.' · ' : '').$site->nom : '—';
    }

    private static function nomObjet(Avenant $a): string
    {
        return match (true) {
            $a->avenantable instanceof Tranche => $a->avenantable->nom,
            $a->avenantable instanceof Convention => $a->avenantable->reference,
            default => __('admin.dividendes.exercice', ['annee' => $a->avenantable?->exercice]),
        };
    }

    private static function url(Avenant $a): string
    {
        return match (true) {
            $a->avenantable instanceof Tranche => route('admin.tranches.show', $a->avenantable),
            $a->avenantable instanceof Convention => route('admin.conventions.show', $a->avenantable),
            default => route('admin.dividendes.show', $a->avenantable),
        };
    }

    /**
     * @return array{libelle: string, detail: ?string, url: string, element: string, objet: string, par: ?string, date: ?CarbonInterface, statut: string}
     */
    private static function element(string $type, string $objet, int|string|null $par, ?CarbonInterface $date, string $statut, string $url, ?string $motif = null): array
    {
        $element = __('admin.tableau.a_traiter.elements.'.$type);

        return [
            'libelle' => $element.' · '.$objet.' · '.$statut, 'detail' => $motif, 'url' => $url,
            'element' => $element, 'objet' => $objet, 'par' => $par, 'date' => $date, 'statut' => $statut, // auteur : profil chargé à la fin
        ];
    }
}
