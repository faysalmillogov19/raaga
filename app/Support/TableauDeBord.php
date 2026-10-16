<?php

namespace App\Support;

use App\Enums\Droit;
use App\Enums\Role;
use App\Enums\StatutBoutique;
use App\Enums\StatutKyc;
use App\Enums\StatutReservationBoutique;
use App\Enums\StatutSite;
use App\Models\Boutique;
use App\Models\CapaciteSite;
use App\Models\Institution;
use App\Models\ReservationBoutique;
use App\Models\Site;
use App\Models\User;
use App\Services\Institutionnels\RegistreA;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Indicateurs du tableau de bord, selon les droits du profil : 4 cartes chiffrées au plus (libellé, valeur, pastille),
 * avancement des sites (boutiques prévues et importées). Un indicateur n'est calculé que si le profil y a droit ;
 * le GESTIONNAIRE DES SITES ET BOUTIQUES n'a aucun indicateur financier.
 */
final class TableauDeBord
{
    private const CARTES_MAX = 4;

    /**
     * @param  list<array{date: ?CarbonInterface}>  $aTraiter
     * @return list<array{cle: string, libelle: string, valeur: string, pastille: string, variante: string, url: ?string}>
     */
    public static function cartes(User $user, array $aTraiter): array
    {
        $k = __('admin.tableau.cartes');
        $candidates = [
            // Tous les profils : ce qui attend une action.
            'actions' => fn () => [count($aTraiter), ($n = collect($aTraiter)->filter(fn ($e) => $e['date']?->gte(now()->subDay()))->count()) > 0
                ? [__('admin.tableau.cartes.actions_depuis_hier', ['n' => $n]), 'avertissement'] : [$k['actions_aucune'], 'ok'], route('admin.tableau').'#a-traiter'],
            'kyc' => $user->can(Droit::KycVoir->value) ? fn () => [User::query()->where('statut_kyc', StatutKyc::EnAttente)->count(), [$k['kyc_delai'], 'info'], route('admin.kyc')] : null,
            // Financier : cautions des candidatures en attente de paiement (virements et espèces à rapprocher).
            'cautions' => $user->can(Droit::BoutiquesVoir->value)
                ? fn () => [ReservationBoutique::query()->where('statut', StatutReservationBoutique::PreReservee)->count(), [$k['cautions_aide'], 'avertissement'], route('admin.boutiques')] : null,
            'boutiques' => $user->can(Droit::SitesVoir->value)
                ? fn () => [Boutique::query()->where('statut', '!=', StatutBoutique::Retiree)->count(), [trans_choice('admin.tableau.cartes.sites_n', $s = Site::query()->count(), ['n' => $s]), 'ok'], route('admin.referencement.boutiques')] : null,
            'sites' => $user->can(Droit::SitesGerer->value)
                ? fn () => [Site::query()->count(), [trans_choice('admin.tableau.cartes.sites_soumis', $s = Site::query()->where('statut', StatutSite::Soumis)->count(), ['n' => $s]), 'info'], route('admin.sites')] : null,
            'capacites' => $user->can(Droit::SitesGerer->value)
                ? fn () => [CapaciteSite::query()->whereNull('nombre_prevu')->whereNull('surface_totale')->count(), [$k['capacites_aide'], 'avertissement'], route('admin.referencement.capacites')] : null,
            // Financier : engagements des institutions (actions A).
            'engagements' => $user->can(Droit::ConventionsVoir->value)
                ? fn () => [format_md_fcfa($e = RegistreA::totalEngage()), [__('admin.tableau.cartes.engagements_aide', ['pct' => ($o = RegistreA::objectifEngagements()) > 0 ? format_nombre($e * 100 / $o, 1)."\u{00A0}%" : '—']), 'info'], route('admin.conventions')] : null,
            'utilisateurs' => $user->can(Droit::UtilisateursGerer->value)
                ? fn () => [User::query()->where('actif', true)->whereHas('role', fn ($q) => $q->whereIn('code', array_map(fn ($r) => $r->value, Role::backoffice())))->count(),
                    [trans_choice('admin.tableau.cartes.utilisateurs_aide', $n = DB::table('invitations')->whereNull('utilisee_le')->whereNull('annulee_le')->where('expire_le', '>', now())->count(), ['n' => $n]), 'info'], route('admin.utilisateurs')] : null,
            'citoyens' => $user->can(Droit::AssistanceVoir->value)
                ? fn () => [User::query()->whereHas('role', fn ($q) => $q->where('code', Role::Citoyen->value))->count(),
                    [__('admin.tableau.cartes.citoyens_aide', ['n' => format_nombre(User::query()->where('statut_kyc', StatutKyc::Valide)->count())]), 'ok'], route('admin.assistance')] : null,
            'institutions' => $user->can(Droit::InstitutionsGerer->value)
                ? fn () => [Institution::query()->count(), [__('admin.tableau.cartes.institutions_aide', ['n' => format_nombre(Institution::query()->where('statut', 'ACTIVE')->count())]), 'ok'], route('admin.institutions')] : null,
        ];

        $cartes = [];
        foreach (array_filter($candidates) as $cle => $calcul) {
            if (count($cartes) === self::CARTES_MAX) {
                break;
            }
            [$valeur, [$pastille, $variante], $url] = $calcul();
            $cartes[] = ['cle' => $cle, 'libelle' => $k[$cle], 'valeur' => is_int($valeur) ? format_nombre($valeur) : $valeur,
                'pastille' => $pastille, 'variante' => $variante, 'url' => $url];
        }

        return $cartes;
    }

    /**
     * Avancement des sites : boutiques importées (hors retirées) et capacité prévue (somme des nombres prévus).
     *
     * @return Collection<int, array{site: Site, reel: int, prevu: ?int, pourcent: int}>
     */
    public static function avancement(User $user): Collection
    {
        if (! $user->can(Droit::SitesVoir->value)) {
            return collect();
        }

        return Site::query()->withCount(['boutiques' => fn ($q) => $q->where('statut', '!=', StatutBoutique::Retiree)])
            ->withSum('capacites', 'nombre_prevu')->orderBy('code')->limit(8)->get()
            ->map(function (Site $s) {
                $prevu = $s->capacites_sum_nombre_prevu !== null ? (int) $s->capacites_sum_nombre_prevu : null;

                return ['site' => $s, 'reel' => (int) $s->boutiques_count, 'prevu' => $prevu,
                    'pourcent' => $prevu ? (int) min(100, round($s->boutiques_count * 100 / $prevu)) : ($s->boutiques_count > 0 ? 100 : 0)];
            });
    }
}
