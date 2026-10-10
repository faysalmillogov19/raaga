<?php

namespace App\Support;

use App\Enums\Droit;
use App\Enums\StatutAppelFonds;
use App\Enums\StatutAvenant;
use App\Enums\StatutConvention;
use App\Enums\StatutDeclarationVirement;
use App\Enums\StatutDistribution;
use App\Enums\StatutDocumentKyc;
use App\Enums\StatutTranche;
use App\Models\AppelFonds;
use App\Models\Avenant;
use App\Models\Convention;
use App\Models\DeclarationVirement;
use App\Models\DistributionDividende;
use App\Models\Institution;
use App\Models\Tranche;
use App\Models\User;

/**
 * Bloc « À traiter » du tableau de bord :
 *  - RÉGULATEUR : paramétrages et avenants rejetés, à reprendre ;
 *  - VALIDATEUR : paramétrages et avenants soumis, en attente de sa décision.
 */
class ATraiter
{
    /** Durée d'affichage d'un avenant rejeté chez le régulateur. */
    private const JOURS_AVENANT_REJETE = 30;

    /**
     * @return list<array{libelle: string, detail: ?string, url: string}>
     */
    public static function pour(User $user): array
    {
        $elements = [];

        if ($user->can(Droit::TranchesSaisir->value)) {
            foreach (Tranche::query()->where('statut', StatutTranche::Brouillon)->whereNotNull('motif_rejet')->get() as $t) {
                $elements[] = self::element('tranche_rejetee', ['nom' => $t->nom], $t->motif_rejet, route('admin.tranches.show', $t));
            }
            $elements = [...$elements, ...self::avenantsRejetes(Tranche::class)];
        }

        if ($user->can(Droit::DividendesSaisir->value)) {
            foreach (DistributionDividende::query()->where('statut', StatutDistribution::Brouillon)->whereNotNull('motif_rejet')->get() as $d) {
                $elements[] = self::element('distribution_rejetee', ['exercice' => $d->exercice], $d->motif_rejet, route('admin.dividendes.show', $d));
            }
            $elements = [...$elements, ...self::avenantsRejetes(DistributionDividende::class)];
        }

        if ($user->can(Droit::TranchesConfirmer->value)) {
            foreach (Tranche::query()->where('statut', StatutTranche::Soumise)->get() as $t) {
                $elements[] = self::element('tranche_soumise', ['nom' => $t->nom, 'n' => $t->version], null, route('admin.tranches.show', $t));
            }
            $elements = [...$elements, ...self::avenantsSoumis(Tranche::class, $user)];
        }

        if ($user->can(Droit::DividendesConfirmer->value)) {
            foreach (DistributionDividende::query()->where('statut', StatutDistribution::Soumis)->get() as $d) {
                $elements[] = self::element('distribution_soumise', ['exercice' => $d->exercice, 'n' => $d->version], null, route('admin.dividendes.show', $d));
            }
            $elements = [...$elements, ...self::avenantsSoumis(DistributionDividende::class, $user)];
        }

        // Actions A : conventions, dossiers KYB, virements à rapprocher, appels en retard.
        if ($user->can(Droit::ConventionsSaisir->value)) {
            foreach (Convention::query()->where('statut', StatutConvention::Brouillon)->whereNotNull('motif_rejet')->get() as $c) {
                $elements[] = self::element('convention_rejetee', ['reference' => $c->reference], $c->motif_rejet, route('admin.conventions.show', $c));
            }
            $elements = [...$elements, ...self::avenantsRejetes(Convention::class)];
        }

        if ($user->can(Droit::ConventionsConfirmer->value)) {
            foreach (Convention::query()->where('statut', StatutConvention::Soumise)->get() as $c) {
                $elements[] = self::element('convention_soumise', ['reference' => $c->reference, 'n' => $c->version], null, route('admin.conventions.show', $c));
            }
            foreach (Convention::query()->whereNull('signee_le')->whereNotNull('signature_deposee_le')->get() as $c) {
                $elements[] = self::element('convention_deposee', ['reference' => $c->reference], null, route('admin.conventions.show', $c));
            }
            $elements = [...$elements, ...self::avenantsSoumis(Convention::class, $user)];
        }

        if ($user->can(Droit::KybVerifier->value)) {
            foreach (Institution::query()->whereHas('documents', fn ($q) => $q->where('statut', StatutDocumentKyc::EnAttente))->get() as $i) {
                $n = $i->piecesEnVigueur()->where('statut', StatutDocumentKyc::EnAttente)->count();
                if ($n > 0) {
                    $elements[] = self::element('kyb_a_verifier', ['institution' => $i->nomCourt(), 'n' => $n], null, route('admin.kyb.show', $i));
                }
            }
        }

        if ($user->can(Droit::AppelsFondsRapprocher->value)) {
            foreach (DeclarationVirement::query()->where('statut', StatutDeclarationVirement::Declaree)->with('appel.institution')->get() as $d) {
                $elements[] = self::element('virement_a_rapprocher', ['institution' => $d->appel->institution->nomCourt(), 'montant' => format_fcfa($d->montant)],
                    null, route('admin.appels-fonds.show', $d->appel_fonds_id));
            }
            foreach (AppelFonds::query()->where('statut', StatutAppelFonds::EnRetard)->with('institution')->get() as $a) {
                $elements[] = self::element('appel_en_retard', ['institution' => $a->institution->nomCourt(), 'n' => $a->numero, 'date' => $a->echeance->format('d/m/Y')],
                    null, route('admin.appels-fonds.show', $a));
            }
        }

        return $elements;
    }

    /**
     * @param  class-string  $type
     * @return list<array{libelle: string, detail: ?string, url: string}>
     */
    private static function avenantsRejetes(string $type): array
    {
        return Avenant::query()
            ->where('avenantable_type', $type)
            ->where('statut', StatutAvenant::Rejete)
            ->where('decide_le', '>=', now()->subDays(self::JOURS_AVENANT_REJETE))
            ->with('avenantable')->latest('id')->get()
            ->map(fn (Avenant $a) => self::element('avenant_rejete', ['objet' => self::nomObjet($a)], $a->motif_decision, self::url($a)))
            ->all();
    }

    /**
     * Avenants en attente, sauf ceux que le validateur aurait lui-même saisis (quatre yeux).
     *
     * @param  class-string  $type
     * @return list<array{libelle: string, detail: ?string, url: string}>
     */
    private static function avenantsSoumis(string $type, User $user): array
    {
        return Avenant::query()
            ->where('avenantable_type', $type)
            ->where('statut', StatutAvenant::EnAttente)
            ->where('saisi_par', '!=', $user->id)
            ->with('avenantable')->oldest('id')->get()
            ->map(fn (Avenant $a) => self::element('avenant_soumis', ['objet' => self::nomObjet($a)], $a->motif, self::url($a)))
            ->all();
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
     * @param  array<string, mixed>  $remplacements
     * @return array{libelle: string, detail: ?string, url: string}
     */
    private static function element(string $cle, array $remplacements, ?string $detail, string $url): array
    {
        return ['libelle' => __('admin.tableau.a_traiter.'.$cle, $remplacements), 'detail' => $detail, 'url' => $url];
    }
}
