<?php

namespace App\Services\Boutiques;

use App\Enums\NiveauBoutique;
use App\Enums\StatutBoutique;
use App\Enums\StatutSite;
use App\Models\Boutique;
use App\Models\CampagneBoutiques;
use App\Models\CategorieBoutique;
use App\Models\GrilleTarifaire;
use App\Models\Site;
use App\Models\TypeBoutique;
use App\Services\Backoffice\RegleGestionException;
use Illuminate\Support\Collection;

/**
 * Lots d'une campagne. Le RÉGULATEUR choisit les types proposés sur chaque site confirmé ; les lots sont construits
 * dans le référentiel, homogènes en prix (site × type × niveau × surface), et le nombre de boutiques y est compté.
 * À l'ouverture, loyer, caution et avance de chaque lot sont calculés depuis la grille tarifaire confirmée du site
 * et figés ; une boutique sans ligne de grille confirmée (ou sans surface) bloque l'ouverture.
 */
class LotsCampagne
{
    /**
     * Types disponibles par site confirmé : [site_id => [type_boutique_id => nombre de boutiques disponibles]].
     *
     * @return Collection<int, Collection<int, int>>
     */
    public static function disponibilites(): Collection
    {
        // Types non attribuables par tirage (chambre froide mutualisée), désactivés ou non validés : jamais proposés.
        return Boutique::query()->whereHas('site', fn ($q) => $q->where('statut', StatutSite::Confirme))
            ->whereHas('type', fn ($q) => $q->utilisables()->where('attribuable', true))
            ->where('statut', StatutBoutique::Disponible)
            ->selectRaw('site_id, type_boutique_id, count(*) as nombre')->groupBy('site_id', 'type_boutique_id')->toBase()->get()
            ->groupBy('site_id')->map(fn ($lignes) => $lignes->pluck('nombre', 'type_boutique_id')->map(fn ($n) => (int) $n));
    }

    /**
     * Remplace les lots de la campagne à partir des types retenus par site.
     *
     * @param  array<int|string, array<int|string, mixed>>  $selection  [site_id][type_id] => coché
     *
     * @throws RegleGestionException
     */
    public function definir(CampagneBoutiques $campagne, array $selection): void
    {
        $disponibles = self::disponibilites();
        $sites = Site::query()->whereIn('id', array_keys($selection))->get()->keyBy('id');
        $types = TypeBoutique::query()->get()->keyBy('id');

        $lots = [];
        foreach ($selection as $siteId => $parType) {
            foreach ($parType as $typeId => $retenu) {
                if (! $retenu) {
                    continue;
                }
                $site = $sites[$siteId] ?? null;
                if (! $site || $site->statut !== StatutSite::Confirme || ($disponibles[$siteId][$typeId] ?? 0) === 0 || ! isset($types[$typeId])) {
                    throw new RegleGestionException(__('admin.boutiques.regles.lot_indisponible'));
                }
                $groupes = Boutique::query()->where('site_id', $site->id)->where('type_boutique_id', $typeId)->where('statut', StatutBoutique::Disponible)
                    ->get()->groupBy(fn (Boutique $b) => $b->niveau?->value.'|'.$b->surface)
                    ->sortBy(fn ($groupe) => [array_search($groupe->first()->niveau, NiveauBoutique::cases(), true), (float) $groupe->first()->surface]);
                foreach ($groupes as $groupe) {
                    $b = $groupe->first();
                    $lots[] = [
                        'site_id' => $site->id, 'type_boutique_id' => (int) $typeId, 'niveau' => $b->niveau, 'surface' => $b->surface,
                        'libelle' => __('admin.boutiques.categorie.libelle_lot', [
                            'type' => $types[$typeId]->libelle, 'niveau' => $b->niveau?->label() ?? '—',
                            'surface' => $b->surface !== null ? format_nombre((float) $b->surface, fmod((float) $b->surface, 1.0) == 0.0 ? 0 : 2) : '—',
                        ]),
                        'description' => $types[$typeId]->description, 'nombre_boutiques' => $groupe->count(),
                    ];
                }
            }
        }
        if (! $lots) {
            throw new RegleGestionException(__('admin.boutiques.erreurs.categories'));
        }

        $campagne->categories()->delete();
        foreach ($lots as $i => $lot) {
            $campagne->categories()->create([...$lot, 'ordre' => $i + 1]);
        }
    }

    /**
     * Montants d'un lot selon la grille confirmée de son site, sans rien enregistrer ; null si la ligne manque.
     *
     * @return array{grille_version: int, loyer_m2: int, mois_caution: int, mois_avance: int, loyer_mensuel: int, caution: int, avance: int}|null
     */
    public function tarifer(CategorieBoutique $lot): ?array
    {
        if ($lot->niveau === null || $lot->surface === null) {
            return null;
        }
        $grille = GrilleTarifaire::query()->where('site_id', $lot->site_id)->first()?->confirmee();
        $ligne = $grille['lignes'][GrilleTarifaire::cle($lot->niveau->value, $lot->type_boutique_id)] ?? null;
        if ($ligne === null) {
            return null;
        }

        return [
            'grille_version' => $grille['version'], ...$ligne,
            ...GrilleTarifaire::montants((float) $lot->surface, $ligne['loyer_m2'], $ligne['mois_caution'], $ligne['mois_avance'], $lot->type->unite_loyer),
        ];
    }

    /**
     * Ouverture : chaque boutique doit avoir une ligne de grille confirmée ; les montants des lots sont figés et
     * les boutiques passent « dans une campagne ».
     *
     * @throws RegleGestionException
     */
    public function engager(CampagneBoutiques $campagne): void
    {
        $lots = $campagne->categories()->whereNotNull('site_id')->with('site')->get();

        // Lot antérieur aux grilles : sa caution saisie suffit ; sinon, la grille confirmée doit couvrir le lot.
        $sansTarif = $lots->filter(fn (CategorieBoutique $lot) => ($lot->niveau !== null || ! $lot->estTarife()) && $this->tarifer($lot) === null);
        if ($sansTarif->isNotEmpty()) {
            throw new RegleGestionException(__('admin.boutiques.regles.sans_grille', ['lots' => $sansTarif->map->intitule()->implode(' ; ')]));
        }

        foreach ($lots as $lot) {
            $boutiques = $this->boutiquesDuLot($lot, StatutBoutique::Disponible);
            if ($boutiques->count() < $lot->nombre_boutiques) {
                throw new RegleGestionException(__('admin.boutiques.regles.boutiques_retirees', ['lot' => $lot->libelle]));
            }
            if ($tarif = $this->tarifer($lot)) {
                $lot->forceFill($tarif)->save();
            }
            Boutique::query()->whereIn('id', $boutiques->take($lot->nombre_boutiques)->pluck('id'))->update(['statut' => StatutBoutique::EnCampagne]);
        }
    }

    /** @return Collection<int, Boutique> */
    public function boutiquesDuLot(CategorieBoutique $lot, StatutBoutique $statut): Collection
    {
        return $lot->requeteBoutiques()->where('statut', $statut)->get();
    }
}
