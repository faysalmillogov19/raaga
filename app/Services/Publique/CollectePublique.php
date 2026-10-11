<?php

namespace App\Services\Publique;

use App\Domain\Allocation\StatistiquesAllocation;
use App\Enums\StatutPaiement;
use App\Enums\StatutSouscription;
use App\Enums\StatutTranche;
use App\Models\Paiement;
use App\Models\Souscription;
use App\Models\Tranche;
use App\Support\VerrouEnvironnement;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;

/**
 * Chiffres publics de la collecte : tranche ouverte en base, ou données de démonstration.
 */
class CollectePublique
{
    /** Durée de mise en cache de la jauge (secondes). */
    public const CACHE_SECONDES = 60;

    /** Durée de mise en cache des statistiques détaillées de la page Transparence (secondes). */
    public const CACHE_STATISTIQUES_SECONDES = 300;

    /**
     * Null : aucune tranche ouverte (et mode démo désactivé).
     */
    public function etat(): ?EtatCollecte
    {
        // Le cache ne garde que des valeurs simples : Laravel refuse de désérialiser des objets
        // (config cache.serializable_classes = false).
        $valeurs = Cache::remember('public.collecte', self::CACHE_SECONDES, function () {
            $etat = $this->depuisLaBase();

            return $etat ? [...get_object_vars($etat), 'dateOuverture' => $etat->dateOuverture->toIso8601String(), 'dateCloture' => $etat->dateCloture->toIso8601String()] : null;
        });
        $etat = $valeurs ? new EtatCollecte(...[...$valeurs, 'dateOuverture' => CarbonImmutable::parse($valeurs['dateOuverture']), 'dateCloture' => CarbonImmutable::parse($valeurs['dateCloture'])]) : null;

        if ($etat === null && VerrouEnvironnement::demonstration()) {
            return DonneesDemo::collecte();
        }

        return $etat;
    }

    public function statistiques(): ?StatistiquesTransparence
    {
        $valeurs = Cache::remember('public.statistiques', self::CACHE_STATISTIQUES_SECONDES, function () {
            $tranche = $this->trancheOuverte();

            return $tranche ? get_object_vars($this->statistiquesDepuisLaBase($tranche)) : null;
        });
        $statistiques = $valeurs ? new StatistiquesTransparence(...$valeurs) : null;

        if ($statistiques === null && VerrouEnvironnement::demonstration()) {
            return DonneesDemo::statistiques();
        }

        return $statistiques;
    }

    /**
     * Pas encore de module de suivi de chantier : seules les données de démonstration en fournissent.
     *
     * @return list<array{titre: string, periode: string, statut: string}>
     */
    public function chantier(): array
    {
        return VerrouEnvironnement::demonstration() ? DonneesDemo::chantier() : [];
    }

    /**
     * Pas encore de module d'actualités : seules les données de démonstration en fournissent.
     *
     * @return list<array{slug: string, etiquette: string, date: CarbonImmutable, titre: string, resume: string, contenu: list<string>}>
     */
    public function actualites(): array
    {
        return VerrouEnvironnement::demonstration() ? DonneesDemo::actualites() : [];
    }

    /**
     * @return array{slug: string, etiquette: string, date: CarbonImmutable, titre: string, resume: string, contenu: list<string>}|null
     */
    public function actualite(string $slug): ?array
    {
        return collect($this->actualites())->firstWhere('slug', $slug);
    }

    private function trancheOuverte(): ?Tranche
    {
        return Tranche::query()
            ->where('statut', StatutTranche::Ouverte)
            ->latest('date_ouverture')
            ->first();
    }

    private function depuisLaBase(): ?EtatCollecte
    {
        $tranche = $this->trancheOuverte();

        if ($tranche === null) {
            return null;
        }

        // Seules les souscriptions payées comptent dans la collecte.
        $totaux = $this->souscriptionsPayees($tranche)
            ->selectRaw('COALESCE(SUM(montant), 0) AS montant, COALESCE(SUM(nb_actions_demandees), 0) AS actions, COUNT(DISTINCT user_id) AS souscripteurs')
            ->first();

        return new EtatCollecte(
            nomTranche: $tranche->nom,
            valeurUnitaire: $tranche->valeur_unitaire,
            plafondActionsParPersonne: $tranche->plafond_actions_par_personne,
            objectifMontant: $tranche->objectif_montant,
            dateOuverture: CarbonImmutable::parse($tranche->date_ouverture),
            dateCloture: CarbonImmutable::parse($tranche->date_cloture),
            montantSouscrit: (int) $totaux->montant,
            nbSouscripteurs: (int) $totaux->souscripteurs,
            nbActions: (int) $totaux->actions,
        );
    }

    private function statistiquesDepuisLaBase(Tranche $tranche): StatistiquesTransparence
    {
        // Montant cumulé par souscripteur (plusieurs souscriptions d'une même personne s'additionnent).
        $parSouscripteur = $this->souscriptionsPayees($tranche)
            ->groupBy('user_id')
            ->selectRaw('SUM(montant) AS total')
            ->pluck('total')
            ->map(fn ($total) => (int) $total)
            ->sort()
            ->values();

        $repartition = array_fill_keys(array_keys(StatistiquesAllocation::TRANCHES_MONTANT), 0);

        foreach ($parSouscripteur as $total) {
            foreach (StatistiquesAllocation::TRANCHES_MONTANT as $libelle => $borne) {
                if ($total <= $borne) {
                    $repartition[$libelle]++;
                    break;
                }
            }
        }

        $parCanal = Paiement::query()
            ->where('statut', StatutPaiement::Confirme)
            ->whereIn('souscription_id', $this->souscriptionsPayees($tranche)->select('id'))
            ->groupBy('canal')
            ->selectRaw('canal, SUM(montant) AS total')
            ->pluck('total', 'canal')
            ->map(fn ($total) => (int) $total)
            ->all();

        return new StatistiquesTransparence(
            montantMedian: $this->mediane($parSouscripteur->all()),
            montantParCanal: $parCanal,
            souscripteursParTrancheMontant: $repartition,
        );
    }

    /**
     * @param  list<int>  $valeursTriees
     */
    private function mediane(array $valeursTriees): int
    {
        $n = count($valeursTriees);

        if ($n === 0) {
            return 0;
        }

        $milieu = intdiv($n, 2);

        return $n % 2 === 1 ? $valeursTriees[$milieu] : intdiv($valeursTriees[$milieu - 1] + $valeursTriees[$milieu], 2);
    }

    private function souscriptionsPayees(Tranche $tranche)
    {
        return Souscription::query()
            ->where('tranche_id', $tranche->id)
            ->whereIn('statut', [StatutSouscription::Payee, StatutSouscription::Allouee]);
    }
}
