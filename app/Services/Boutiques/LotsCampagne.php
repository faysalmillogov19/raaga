<?php

namespace App\Services\Boutiques;

use App\Enums\StatutBoutique;
use App\Enums\StatutSite;
use App\Models\Boutique;
use App\Models\CampagneBoutiques;
use App\Models\CategorieBoutique;
use App\Models\Site;
use App\Models\TypeBoutique;
use App\Services\Backoffice\RegleGestionException;
use Illuminate\Support\Collection;

/**
 * Lots d'une campagne (un site confirmé × un type de boutique, avec sa caution). Le nombre de boutiques
 * d'un lot n'est jamais saisi : il est compté dans le référentiel (boutiques disponibles du site et du type).
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
        return Boutique::query()->whereHas('site', fn ($q) => $q->where('statut', StatutSite::Confirme))
            ->where('statut', StatutBoutique::Disponible)
            ->selectRaw('site_id, type_boutique_id, count(*) as nombre')->groupBy('site_id', 'type_boutique_id')->toBase()->get()
            ->groupBy('site_id')->map(fn ($lignes) => $lignes->pluck('nombre', 'type_boutique_id')->map(fn ($n) => (int) $n));
    }

    /**
     * Remplace les lots de la campagne. Une caution vide = type non proposé sur ce site.
     *
     * @param  array<int|string, array<int|string, int|string|null>>  $cautions  [site_id][type_id] => caution
     *
     * @throws RegleGestionException
     */
    public function definir(CampagneBoutiques $campagne, array $cautions): void
    {
        $disponibles = self::disponibilites();
        $sites = Site::query()->whereIn('id', array_keys($cautions))->get()->keyBy('id');
        $types = TypeBoutique::query()->get()->keyBy('id');

        $lots = [];
        foreach ($cautions as $siteId => $parType) {
            foreach ($parType as $typeId => $caution) {
                if ($caution === null || $caution === '') {
                    continue;
                }
                $site = $sites[$siteId] ?? null;
                $nombre = $disponibles[$siteId][$typeId] ?? 0;
                if (! $site || $site->statut !== StatutSite::Confirme || $nombre === 0 || ! isset($types[$typeId])) {
                    throw new RegleGestionException(__('admin.boutiques.regles.lot_indisponible'));
                }
                $lots[] = [
                    'site_id' => $site->id, 'type_boutique_id' => (int) $typeId, 'libelle' => $types[$typeId]->libelle,
                    'description' => $types[$typeId]->description, 'nombre_boutiques' => $nombre, 'caution' => (int) $caution,
                ];
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
     * Ouverture : les boutiques des lots passent « dans une campagne » ; le nombre doit toujours être disponible.
     *
     * @throws RegleGestionException
     */
    public function engager(CampagneBoutiques $campagne): void
    {
        foreach ($campagne->categories()->whereNotNull('site_id')->get() as $lot) {
            $boutiques = $this->boutiquesDuLot($lot, StatutBoutique::Disponible);
            if ($boutiques->count() < $lot->nombre_boutiques) {
                throw new RegleGestionException(__('admin.boutiques.regles.boutiques_retirees', ['lot' => $lot->libelle]));
            }
            Boutique::query()->whereIn('id', $boutiques->take($lot->nombre_boutiques)->pluck('id'))->update(['statut' => StatutBoutique::EnCampagne]);
        }
    }

    /** @return Collection<int, Boutique> */
    public function boutiquesDuLot(CategorieBoutique $lot, StatutBoutique $statut): Collection
    {
        return Boutique::query()->where('site_id', $lot->site_id)->where('type_boutique_id', $lot->type_boutique_id)
            ->where('statut', $statut)->orderByRaw('code IS NULL, code, LENGTH(numero), numero')->get();
    }
}
