<?php

namespace App\Services\Dividendes;

use App\Domain\Dividendes\Detention;
use App\Domain\Dividendes\LigneDistribution;
use App\Domain\Dividendes\MoteurDistribution;
use App\Domain\Dividendes\ParametresDistribution;
use App\Domain\Dividendes\ResultatDistribution;
use App\Enums\ClasseActions;
use App\Enums\ModeCalculDividende;
use App\Enums\PayeurFrais;
use App\Models\BlocageActions;
use App\Models\DistributionDividende;
use App\Models\LigneRegistre;
use App\Models\LigneRegistreA;
use App\Models\MoyenPaiement;

/**
 * Simulation d'une distribution à la date d'arrêté : registre des actions B (citoyens) ou registre des
 * actions A (institutions) selon la classe du paramétrage.
 */
class SimulationDistribution
{
    public function __construct(private readonly MoteurDistribution $moteur = new MoteurDistribution) {}

    public function simuler(DistributionDividende $distribution): ResultatDistribution
    {
        return $this->moteur->calculer($this->parametres($distribution), $this->detentionsAuRegistre($distribution));
    }

    public function parametres(DistributionDividende $d): ParametresDistribution
    {
        $global = $d->mode_calcul === ModeCalculDividende::MontantGlobal;

        return new ParametresDistribution(
            modeMontantGlobal: $global,
            montantGlobal: $global ? $d->montant_global : null,
            dividendeParAction: $global ? null : $d->dividende_brut_par_action,
            tauxRetenuePb: $d->taux_irvm_pb,
            fraisParActionnaire: $d->frais_supportes_par === PayeurFrais::Souscripteur,
            seuilMinimal: $d->seuil_minimal,
            canalDefaut: $d->canal_defaut->code,
            canauxAutorises: $d->canaux_autorises,
            fraisCanaux: config('raaga.dividendes.frais_versement', []),
        );
    }

    /**
     * Ligne de distribution d'une institution (actions A) : brut, retenue, net · null si elle ne détenait
     * aucune action A à la date d'arrêté.
     */
    public function ligneInstitution(DistributionDividende $distribution, int $institutionId): ?LigneDistribution
    {
        return collect($this->simuler($distribution)->lignes)->first(fn (LigneDistribution $l) => $l->userId === $institutionId);
    }

    /**
     * Actionnaires inscrits au registre à la date d'arrêté, avec leurs blocages actifs à cette date.
     * Actions A : une détention par institution, versée par virement sur son RIB vérifié.
     *
     * @return list<Detention>
     */
    private function detentionsAuRegistre(DistributionDividende $d): array
    {
        $arrete = $d->date_arrete->endOfDay();

        if ($d->classe_actions === ClasseActions::A) {
            return LigneRegistreA::query()
                ->where('date_inscription', '<=', $arrete->toDateString())
                ->groupBy('institution_id')
                ->selectRaw('institution_id, SUM(nb_actions) AS actions')
                ->orderBy('institution_id')
                ->get()
                ->map(fn ($l) => new Detention((int) $l->institution_id, (int) $l->actions, canal: MoyenPaiement::VIREMENT))
                ->all();
        }

        $bloques = BlocageActions::query()
            ->where('debut', '<=', $arrete)
            ->where(fn ($q) => $q->whereNull('fin')->orWhere('fin', '>', $arrete))
            ->pluck('user_id')
            ->flip();

        return LigneRegistre::query()
            ->where('date_inscription', '<=', $arrete)
            ->groupBy('user_id')
            ->selectRaw('user_id, SUM(nb_actions) AS actions')
            ->orderBy('user_id')
            ->get()
            ->map(fn ($l) => new Detention((int) $l->user_id, (int) $l->actions, bloquee: $bloques->has($l->user_id)))
            ->all();
    }
}
