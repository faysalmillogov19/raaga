<?php

namespace App\Services\Dividendes;

use App\Domain\Dividendes\Detention;
use App\Domain\Dividendes\MoteurDistribution;
use App\Domain\Dividendes\ParametresDistribution;
use App\Domain\Dividendes\ResultatDistribution;
use App\Enums\ModeCalculDividende;
use App\Enums\PayeurFrais;
use App\Models\BlocageActions;
use App\Models\DistributionDividende;
use App\Models\LigneRegistre;

/**
 * Simulation d'une distribution : registre des actions B à la date d'arrêté (ou jeu fictif de démonstration).
 */
class SimulationDistribution
{
    public const SOURCE_REGISTRE = 'registre';

    public const SOURCE_DEMO = 'demo';

    public function __construct(private readonly MoteurDistribution $moteur = new MoteurDistribution) {}

    public function simuler(DistributionDividende $distribution, string $source = self::SOURCE_REGISTRE): ResultatDistribution
    {
        $detentions = $source === self::SOURCE_DEMO
            ? $this->detentionsFictives()
            : $this->detentionsAuRegistre($distribution);

        return $this->moteur->calculer($this->parametres($distribution), $detentions);
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
            canalDefaut: $d->canal_defaut->value,
            canauxAutorises: $d->canaux_autorises,
            fraisCanaux: config('raaga.dividendes.frais_versement', []),
        );
    }

    /**
     * Actionnaires inscrits au registre à la date d'arrêté, avec leurs blocages actifs à cette date.
     *
     * @return list<Detention>
     */
    private function detentionsAuRegistre(DistributionDividende $d): array
    {
        $arrete = $d->date_arrete->endOfDay();

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

    /**
     * Jeu fictif et reproductible (200 actionnaires) pour tester les paramètres avant la première allocation.
     *
     * @return list<Detention>
     */
    private function detentionsFictives(): array
    {
        mt_srand(2026);
        $canaux = ['ORANGE_MONEY', 'MOOV_MONEY', 'VIREMENT', null];
        $detentions = [];

        for ($i = 1; $i <= 200; $i++) {
            $tirage = mt_rand(1, 100);
            $actions = match (true) {
                $tirage <= 60 => mt_rand(1, 10),
                $tirage <= 90 => mt_rand(10, 100),
                $tirage <= 98 => mt_rand(100, 1_000),
                default => mt_rand(1_000, 5_000),
            };
            $detentions[] = new Detention("demo-{$i}", $actions, bloquee: $i % 67 === 0, canal: $canaux[mt_rand(0, 3)]);
        }

        mt_srand();

        return $detentions;
    }
}
