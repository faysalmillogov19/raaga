<?php

namespace App\Http\Controllers\Institutionnel;

use App\Domain\Dividendes\LigneDistribution;
use App\Enums\ClasseActions;
use App\Enums\StatutDistribution;
use App\Models\DistributionDividende;
use App\Services\Dividendes\SimulationDistribution;
use App\Services\Documents\GenerateurPdf;
use Illuminate\Http\Response;
use Illuminate\Support\Collection;
use Illuminate\View\View;

/**
 * « Dividendes » : distributions des actions A confirmées dont l'institution est bénéficiaire, avec
 * l'avis de distribution PDF (brut, retenue, net) ; versement par virement sur le RIB vérifié.
 */
class DividendesController extends Controleur
{
    public function __construct(private readonly SimulationDistribution $simulation) {}

    public function index(): View
    {
        return view('institutionnel.dividendes', ['distributions' => $this->distributions()]);
    }

    public function avis(DistributionDividende $distribution, GenerateurPdf $pdf): Response
    {
        $ligne = $this->ligne($distribution) ?? abort(403);

        return response($pdf->rendre('documents.avis-dividende-a', [
            'distribution' => $distribution,
            'institution' => $this->institution(),
            'ligne' => $ligne,
        ]), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="avis-dividende-'.$distribution->exercice.'-actions-a.pdf"',
        ]);
    }

    /**
     * Distributions A confirmées (ou en paiement, payées) avec la ligne de l'institution.
     *
     * @return Collection<int, array{distribution: DistributionDividende, ligne: LigneDistribution}>
     */
    public function distributions(): Collection
    {
        return DistributionDividende::query()
            ->where('classe_actions', ClasseActions::A)
            ->whereIn('statut', [StatutDistribution::Confirme, StatutDistribution::EnPaiement, StatutDistribution::Paye])
            ->orderByDesc('exercice')->get()
            ->map(fn (DistributionDividende $d) => ['distribution' => $d, 'ligne' => $this->ligne($d)])
            ->filter(fn (array $e) => $e['ligne'] !== null)
            ->values();
    }

    /** Ligne de l'institution connectée : jamais celle d'une autre institution. */
    private function ligne(DistributionDividende $distribution): ?LigneDistribution
    {
        if ($distribution->classe_actions !== ClasseActions::A
            || ! in_array($distribution->statut, [StatutDistribution::Confirme, StatutDistribution::EnPaiement, StatutDistribution::Paye], true)) {
            return null;
        }

        return $this->simulation->ligneInstitution($distribution, $this->institution()->id);
    }
}
