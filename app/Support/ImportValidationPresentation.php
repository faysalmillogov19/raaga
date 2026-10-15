<?php

namespace App\Support;

use App\Models\ImportValidation;
use App\Models\ImportValidationSite;
use App\Models\Site;
use App\Models\TypeBoutique;
use App\Services\Sites\ComparaisonCapacites;
use Illuminate\Support\Collection;
use Illuminate\Support\HtmlString;

/** Affichage d'un import et de sa validation : contenu, statut, résumé par site, niveau et type, écarts de capacités. */
final class ImportValidationPresentation
{
    public static function contenu(ImportValidation $i): string
    {
        $c = $i->contenu;
        $x = __('admin.imports_validation.contenu');

        return $i->entite === 'boutiques'
            ? strtr($x['boutiques'], [':sites' => $i->sites->count(), ':creees' => $c['creees'] ?? 0, ':mises_a_jour' => $c['mises_a_jour'] ?? 0, ':inchangees' => $c['inchangees'] ?? 0])
            : strtr($x['sites'], [':crees' => $c['sites_crees'] ?? 0, ':mis_a_jour' => $c['sites_mis_a_jour'] ?? 0, ':inchanges' => $c['sites_inchanges'] ?? 0, ':capacites' => $c['capacites'] ?? 0]);
    }

    /** Badge du statut de validation, avec le motif d'un rejet. */
    public static function statut(ImportValidation $i): HtmlString
    {
        $statut = $i->statutValidation();
        $html = BadgeStatut::html(__('admin.imports_validation.statuts.'.$statut))->toHtml();
        if ($statut === 'REJETE' && $i->motif_rejet) {
            $html .= '<br><span class="text-sm">'.e(__('admin.imports_validation.motif', ['motif' => $i->motif_rejet])).'</span>';
        }

        return new HtmlString($html);
    }

    /** Situation d'un site dans l'import : confirmé ou rejeté par l'import, en attente, traité séparément, resté en brouillon. */
    public static function situation(ImportValidationSite $l): string
    {
        $r = __('admin.imports_validation.resultats_site');

        return match (true) {
            $l->resultat !== null => $r[$l->resultat],
            $l->soumis_le === null => $r['brouillon'],
            $l->enAttente() => $r['attente'],
            default => $r['separe'],
        };
    }

    /** @return Collection<int, array{site: string, niveau: string, type: string, lignes: int, creees: int, mises_a_jour: int, nombre_prevu: int}> */
    public static function resume(ImportValidation $i): Collection
    {
        $codes = Site::query()->whereIn('id', array_filter(array_column($i->resume, 'site_id')))->pluck('code', 'id');
        $types = TypeBoutique::query()->pluck('libelle', 'id');

        return collect($i->resume)->map(fn (array $r) => [...$r,
            'site' => $r['site'] ?? ($codes[$r['site_id']] ?? ''),
            'niveau' => $r['niveau'] ?? __('admin.imports_validation.tous_niveaux'),
            'type' => $types[$r['type_boutique_id']] ?? '',
        ])->sortBy(['site', 'niveau', 'type'])->values();
    }

    /** Lignes de capacité prévue des sites de l'import où le référentiel s'écarte du nombre prévu. */
    public static function ecarts(ImportValidation $i): Collection
    {
        $comparaison = app(ComparaisonCapacites::class);

        return $i->sites->flatMap(fn (ImportValidationSite $l) => $comparaison->pour($l->site)
            ->filter(fn (array $c) => $c['nombre_prevu'] === null ? $c['nombre_reel'] > 0 : $c['nombre_reel'] !== $c['nombre_prevu'])
            ->map(fn (array $c) => ['site' => $l->site->code, 'niveau' => $c['code_niveau'] ?? __('admin.imports_validation.tous_niveaux'),
                'type' => $c['type']->libelle, 'prevu' => $c['nombre_prevu'], 'reel' => $c['nombre_reel'], 'ecart' => $c['nombre_reel'] - (int) $c['nombre_prevu']]))->values();
    }
}
