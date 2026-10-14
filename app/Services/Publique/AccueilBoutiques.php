<?php

namespace App\Services\Publique;

use App\Enums\StatutBoutique;
use App\Enums\StatutSite;
use App\Models\Boutique;
use App\Models\GrilleTarifaire;
use App\Models\Niveau;
use App\Models\Site;
use Illuminate\Support\Collection;

/**
 * Données de la page d'accueil (phase 1 : boutiques), toutes lues dans la base : chiffres clés, durées de caution et
 * d'avance par niveau (grilles tarifaires confirmées), sites confirmés. Aucun montant n'est affiché ici.
 */
final class AccueilBoutiques
{
    /** @return array{boutiques: int, niveaux: int, sites: int} */
    public function chiffres(): array
    {
        return [
            'boutiques' => Boutique::query()->where('statut', '!=', StatutBoutique::Retiree)->count(),
            'niveaux' => Niveau::query()->utilisables()->count(),
            'sites' => Site::query()->where('statut', StatutSite::Confirme)->count(),
        ];
    }

    /**
     * Mois de caution et d'avance par niveau, d'après la dernière version confirmée de chaque grille (une grille en
     * révision garde sa version confirmée). Vide si aucune grille n'est confirmée.
     *
     * @return Collection<int, array{niveau: string, caution: array{0: int, 1: int}, avance: array{0: int, 1: int}}>
     */
    public function durees(): Collection
    {
        $parNiveau = [];
        foreach (GrilleTarifaire::query()->get() as $grille) {
            foreach ($grille->confirmee()['lignes'] ?? [] as $cle => $ligne) {
                $niveau = explode('|', (string) $cle)[0];
                $parNiveau[$niveau]['caution'][] = (int) $ligne['mois_caution'];
                $parNiveau[$niveau]['avance'][] = (int) $ligne['mois_avance'];
            }
        }
        $niveaux = Niveau::query()->whereIn('code', array_keys($parNiveau))->orderBy('rang')->get();

        return $niveaux->map(fn (Niveau $n) => [
            'niveau' => $n->libelle,
            'caution' => [min($parNiveau[$n->code]['caution']), max($parNiveau[$n->code]['caution'])],
            'avance' => [min($parNiveau[$n->code]['avance']), max($parNiveau[$n->code]['avance'])],
        ])->values();
    }

    /** Sites confirmés, avec leur nombre de boutiques (hors retirées). @return Collection<int, Site> */
    public function sites(): Collection
    {
        return Site::query()->where('statut', StatutSite::Confirme)
            ->withCount(['boutiques' => fn ($q) => $q->where('statut', '!=', StatutBoutique::Retiree)])
            ->orderBy('nom')->get();
    }
}
