<?php

namespace App\Http\Controllers\Compte;

use App\Enums\StatutCandidatureBoutique;
use App\Http\Controllers\Compte\Concerns\GerePaiements;
use App\Http\Controllers\Controller;
use App\Models\CandidatureBoutique;
use App\Services\Documents\GenerateurPdf;
use App\Services\Paiement\ServicePaiement;
use App\Support\Recu;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\View\View;

/** Paiement de la caution d'une candidature aux boutiques (même module que les souscriptions d'actions). */
class CautionController extends Controller
{
    use GerePaiements;

    /** Statuts pour lesquels la caution a été payée (reçu disponible). */
    private const PAYEES = [StatutCandidatureBoutique::Payee, StatutCandidatureBoutique::Retenue, StatutCandidatureBoutique::NonRetenue];

    public function __construct(private readonly ServicePaiement $service) {}

    public function show(Request $request, string $reference): View|RedirectResponse
    {
        $this->service->expirerReservations();
        $c = $this->candidature($request, $reference);

        if (in_array($c->statut, self::PAYEES, true)) {
            return redirect()->route('boutiques.candidature', $c->reference);
        }

        $titre = __('public.boutiques.paiement_caution');

        return $this->ecranPaiement($c->load(['campagne', 'categorie']), [
            'titre' => $titre,
            'fil' => [['libelle' => __('public.boutiques.titre'), 'url' => route('boutiques')], ['libelle' => $titre]],
            'alerte' => session('candidature.creee')
                ? __('public.boutiques.candidature.creee', ['reference' => '<span class="whitespace-nowrap font-bold">'.e($c->reference).'</span>'])
                : null,
            'lignes' => [
                [__('public.boutiques.candidature.campagne'), $c->campagne->nom],
                [__('public.boutiques.candidature.categorie'), $c->categorie->libelle],
                // Caution et avance : deux lignes distinctes, avec leur mention.
                ...array_map(fn ($l) => [$l['libelle'], format_fcfa($l['montant'])], $c->lignesMontants()),
            ],
            'mentions' => array_column($c->lignesMontants(), 'mention'),
            'total' => $c->montantAPayer(),
            'routes' => collect(['show', 'payer', 'statut', 'simuler', 'changer'])
                ->mapWithKeys(fn (string $r) => [$r => route('boutiques.caution.'.$r, $c->reference)])->all(),
            'nouvelle' => ['url' => route('boutiques'), 'libelle' => __('public.boutiques.titre')],
        ]);
    }

    public function payer(Request $request, string $reference): RedirectResponse
    {
        $candidature = $this->candidature($request, $reference);
        $this->demanderPaiement($request, $candidature->load('campagne'));

        return redirect()->route('boutiques.caution.show', $candidature->reference);
    }

    public function statut(Request $request, string $reference): JsonResponse
    {
        $this->service->expirerReservations();

        return $this->statutPaiement($this->candidature($request, $reference));
    }

    public function simuler(Request $request, string $reference): RedirectResponse
    {
        $candidature = $this->candidature($request, $reference);
        $this->simulerPaiement($request, $candidature);

        return redirect()->route('boutiques.caution.show', $candidature->reference);
    }

    public function changer(Request $request, string $reference): RedirectResponse
    {
        $candidature = $this->candidature($request, $reference);
        $this->abandonnerPaiement($candidature);

        return redirect()->route('boutiques.caution.show', $candidature->reference);
    }

    /** Reçu de caution (PDF), disponible dès le paiement confirmé. */
    public function recu(Request $request, string $reference, GenerateurPdf $pdf): Response
    {
        $candidature = $this->candidature($request, $reference);
        abort_unless(in_array($candidature->statut, self::PAYEES, true), 404);
        $paiement = Recu::paiement($candidature) ?? abort(404);
        $numero = Recu::numero($paiement);

        return response($pdf->rendre('documents.recu-caution', [
            'candidature' => $candidature->load(['campagne', 'categorie', 'user']),
            'paiement' => $paiement,
            'numero' => $numero,
        ]), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="recu-caution-'.$numero.'.pdf"',
        ]);
    }

    /** Candidature du citoyen connecté ; celle d'un autre citoyen est refusée. */
    private function candidature(Request $request, string $reference): CandidatureBoutique
    {
        $candidature = CandidatureBoutique::query()->where('reference', $reference)->firstOrFail();
        abort_unless($candidature->user_id === $request->user()->id, 403);

        return $candidature;
    }
}
