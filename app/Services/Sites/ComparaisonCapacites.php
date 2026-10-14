<?php

namespace App\Services\Sites;

use App\Enums\StatutBoutique;
use App\Models\Boutique;
use App\Models\CapaciteSite;
use App\Models\Niveau;
use App\Models\Site;
use App\Models\TypeBoutique;
use Illuminate\Support\Collection;

/**
 * Capacité prévue d'un site comparée, par niveau et par type, aux boutiques réellement au référentiel.
 * Les zones d'un même niveau et type sont additionnées. Une capacité sans niveau (magasins, restaurant…) se
 * compare à toutes les boutiques du type, quel que soit leur niveau. Les boutiques retirées ne comptent pas.
 */
class ComparaisonCapacites
{
    /** Tolérance d'arrondi sur les surfaces (m²). */
    private const TOLERANCE_SURFACE = 0.01;

    /**
     * @return Collection<int, array{niveau: ?Niveau, type: TypeBoutique, zones: list<string>, nombre_prevu: ?int, surface_prevue: ?float,
     *     nombre_reel: int, surface_reelle: float, etat: string, ecart_nombre: ?int, ecart_surface: ?float}>
     */
    public function pour(Site $site): Collection
    {
        $niveaux = Niveau::query()->get()->keyBy('code');
        $capacites = $site->capacites()->with('type')->get();
        $boutiques = $site->boutiques()->where('statut', '!=', StatutBoutique::Retiree)->with('type')->get();
        $lignes = collect();

        foreach ($capacites->groupBy('type_boutique_id') as $parType) {
            $tousNiveaux = $parType->contains(fn (CapaciteSite $c) => $c->niveau === null);
            foreach ($tousNiveaux ? ['' => $parType] : $parType->groupBy('niveau') as $code => $groupe) {
                $code = $code === '' ? null : (string) $code;
                $reelles = $boutiques->where('type_boutique_id', $parType->first()->type_boutique_id)
                    ->filter(fn (Boutique $b) => $code === null || $b->niveau === $code);
                $lignes->push($this->ligne($code === null ? null : $niveaux->get($code), $code, $parType->first()->type, $groupe, $reelles));
            }
        }

        // Boutiques d'un niveau et d'un type que rien ne prévoit.
        foreach ($boutiques->groupBy(fn (Boutique $b) => $b->niveau.'|'.$b->type_boutique_id) as $groupe) {
            $b = $groupe->first();
            $couvert = $lignes->contains(fn ($l) => $l['type']->id === $b->type_boutique_id && ($l['code_niveau'] === null || $l['code_niveau'] === $b->niveau));
            if (! $couvert) {
                $lignes->push($this->ligne($niveaux->get((string) $b->niveau), $b->niveau, $b->type, collect(), $groupe));
            }
        }

        return $lignes->sortBy(fn ($l) => [$l['code_niveau'] === null ? 999 : ($l['niveau']?->rang ?? 998), $l['type']->ordre, $l['type']->libelle])->values();
    }

    /**
     * @param  Collection<int, CapaciteSite>  $capacites
     * @param  Collection<int, Boutique>  $reelles
     */
    private function ligne(?Niveau $niveau, ?string $code, TypeBoutique $type, Collection $capacites, Collection $reelles): array
    {
        $nombres = $capacites->pluck('nombre_prevu')->filter(fn ($n) => $n !== null);
        $surfaces = $capacites->map(fn (CapaciteSite $c) => $c->surfaceRetenue())->filter(fn ($s) => $s !== null);
        $nombrePrevu = $nombres->isEmpty() ? null : (int) $nombres->sum();
        $surfacePrevue = $surfaces->isEmpty() ? null : round((float) $surfaces->sum(), 2);
        $nombreReel = $reelles->count();
        $surfaceReelle = round((float) $reelles->sum(fn (Boutique $b) => (float) $b->surface), 2);

        $ecartNombre = $nombrePrevu === null ? null : $nombreReel - $nombrePrevu;
        $ecartSurface = $surfacePrevue === null ? null : round($surfaceReelle - $surfacePrevue, 2);
        $etat = match (true) {
            $capacites->isEmpty() => 'non_prevu',
            $nombrePrevu === null && $surfacePrevue === null => 'a_completer',
            ($ecartNombre !== null && $ecartNombre !== 0) || ($ecartSurface !== null && abs($ecartSurface) > self::TOLERANCE_SURFACE) => 'ecart',
            default => 'conforme',
        };

        return [
            'niveau' => $niveau, 'code_niveau' => $code, 'type' => $type, 'zones' => $capacites->pluck('zone')->filter()->unique()->values()->all(),
            'nombre_prevu' => $nombrePrevu, 'surface_prevue' => $surfacePrevue, 'nombre_reel' => $nombreReel, 'surface_reelle' => $surfaceReelle,
            'etat' => $etat, 'ecart_nombre' => $ecartNombre, 'ecart_surface' => $ecartSurface,
        ];
    }
}
