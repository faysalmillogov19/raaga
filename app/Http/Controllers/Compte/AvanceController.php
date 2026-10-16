<?php

namespace App\Http\Controllers\Compte;

use App\Http\Controllers\Compte\Concerns\GerePaiements;
use App\Http\Controllers\Controller;
use App\Models\Paiement;
use App\Models\ReservationBoutique;
use App\Services\Documents\GenerateurPdf;
use App\Services\Paiement\ServicePaiement;
use App\Support\Recu;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\View\View;

/**
 * Paiement de l'avance d'une boutique réservée, par le même circuit que la caution : montant figé depuis la grille
 * confirmée, moyens ouverts et autorisés pour la location (plafonds respectés), compte de location ; opérateur
 * (confirmation automatique) ou paiement manuel (référence unique, preuve, contrôle, délai suspendu pendant le
 * contrôle, délai de complément si incomplet). En retard, l'avance se paie encore tant qu'aucune annulation n'est
 * confirmée.
 */
class AvanceController extends Controller
{
    use GerePaiements;

    public function __construct(private readonly ServicePaiement $service) {}

    public function show(Request $request, string $reference): View|RedirectResponse
    {
        $r = $this->reservation($request, $reference);
        if ($r->naturePaiementAttendue() !== Paiement::AVANCE) {
            return redirect()->route('boutiques.suivi', $r->reference);
        }

        $titre = __('public.boutiques.paiement_avance');
        $t = __('public.boutiques.reservation');

        return $this->ecranPaiement($r->load(['campagne', 'boutique.type']), [
            'titre' => $titre,
            'fil' => [['libelle' => __('public.boutiques.titre'), 'url' => route('boutiques')], ['libelle' => $r->reference, 'url' => route('boutiques.suivi', $r->reference)], ['libelle' => $titre]],
            'alerte' => $r->avance_due_le?->isPast() ? __('public.boutiques.avance.retard', ['date' => $r->avance_due_le->translatedFormat('j F Y à H\hi')]) : null,
            'lignes' => [
                [$t['boutique'], (string) $r->boutique?->code],
                [$t['type'], (string) $r->boutique?->type?->libelle],
                [$t['loyer'], format_fcfa((int) $r->loyer_mensuel)],
                [__('public.boutiques.avance.caution_payee'), format_fcfa((int) $r->caution)],
                [__('public.boutiques.avance.montant'), format_fcfa((int) $r->avance)],
            ],
            'mentions' => [trans_choice('public.boutiques.montants.mention_avance', (int) $r->mois_avance, ['mois' => (int) $r->mois_avance]), __('public.boutiques.avance.avant_contrat')],
            'total' => $r->montantAPayer(),
            // Avance : aucune expiration automatique, le retard est traité au cas par cas.
            'avant' => 'public.boutiques.avance.avant',
            'routes' => collect(['show', 'payer', 'statut', 'simuler', 'changer', 'preuve'])
                ->mapWithKeys(fn (string $a) => [$a => route('boutiques.avance.'.$a, $r->reference)])->all(),
            'nouvelle' => ['url' => route('boutiques.suivi', $r->reference), 'libelle' => $t['suivi_titre']],
        ]);
    }

    public function payer(Request $request, string $reference): RedirectResponse
    {
        $r = $this->reservation($request, $reference);
        abort_unless($r->naturePaiementAttendue() === Paiement::AVANCE, 409);
        $this->demanderPaiement($request, $r->load('campagne'));

        return redirect()->route('boutiques.avance.show', $r->reference);
    }

    public function statut(Request $request, string $reference): JsonResponse
    {
        return $this->statutPaiement($this->reservation($request, $reference));
    }

    public function simuler(Request $request, string $reference): RedirectResponse
    {
        $r = $this->reservation($request, $reference);
        $this->simulerPaiement($request, $r);

        return redirect()->route('boutiques.avance.show', $r->reference);
    }

    public function preuve(Request $request, string $reference): RedirectResponse
    {
        $r = $this->reservation($request, $reference);
        $this->deposerPreuve($request, $r);

        return redirect()->route('boutiques.avance.show', $r->reference)->with('succes', __('public.paiement.manuel.preuve_envoyee'));
    }

    public function changer(Request $request, string $reference): RedirectResponse
    {
        $r = $this->reservation($request, $reference);
        $this->abandonnerPaiement($r);

        return redirect()->route('boutiques.avance.show', $r->reference);
    }

    /** Reçu de l'avance (PDF), dès le paiement confirmé. */
    public function recu(Request $request, string $reference, GenerateurPdf $pdf): Response
    {
        $r = $this->reservation($request, $reference);
        $paiement = $r->avance_payee_le ? Recu::paiement($r, Paiement::AVANCE) : null;
        abort_unless($paiement, 404);
        $numero = Recu::numero($paiement);

        return response($pdf->rendre('documents.recu-caution', [
            'candidature' => $r->load(['campagne', 'boutique.type', 'user']),
            'paiement' => $paiement,
            'numero' => $numero,
            'avance' => true,
        ]), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="recu-avance-'.$numero.'.pdf"',
        ]);
    }

    /** Réservation du citoyen connecté ; celle d'un autre citoyen est refusée. */
    private function reservation(Request $request, string $reference): ReservationBoutique
    {
        $r = ReservationBoutique::query()->where('reference', $reference)->firstOrFail();
        abort_unless($r->user_id === $request->user()->id, 403);

        return $r;
    }
}
