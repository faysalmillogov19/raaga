<?php

namespace App\Http\Controllers\Compte;

use App\Enums\StatutReservationBoutique;
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

/** Paiement de la caution d'une réservation de boutique (même module que les souscriptions d'actions). */
class CautionController extends Controller
{
    use GerePaiements;

    /** Statuts pour lesquels la caution a été payée (reçu disponible). */
    private const PAYEES = [StatutReservationBoutique::Reservee, StatutReservationBoutique::Attribuee, StatutReservationBoutique::Payee, StatutReservationBoutique::Retenue];

    public function __construct(private readonly ServicePaiement $service) {}

    public function show(Request $request, string $reference): View|RedirectResponse
    {
        $this->service->expirerReservations();
        $c = $this->reservation($request, $reference);

        if (in_array($c->statut, self::PAYEES, true)) {
            return redirect()->route('boutiques.suivi', $c->reference);
        }

        $titre = __('public.boutiques.paiement_caution');
        $r = __('public.boutiques.reservation');

        return $this->ecranPaiement($c->load(['campagne', 'boutique.type']), [
            'titre' => $titre,
            'fil' => [['libelle' => __('public.boutiques.titre'), 'url' => route('boutiques')], ['libelle' => $titre]],
            'alerte' => session('reservation.creee')
                ? __('public.boutiques.reservation.creee', ['code' => '<span class="whitespace-nowrap font-bold">'.e($c->boutique?->code).'</span>'])
                : null,
            'lignes' => [
                [$r['boutique'], (string) $c->boutique?->code],
                [$r['type'], (string) $c->boutique?->type?->libelle],
                [$r['loyer'], format_fcfa((int) $c->loyer_mensuel)],
                // Caution (à payer maintenant) et avance (due avant la signature du contrat) : deux lignes distinctes.
                ...array_map(fn ($l) => [$l['libelle'], format_fcfa($l['montant'])], $c->lignesMontants()),
            ],
            'mentions' => [...array_column($c->lignesMontants(), 'mention'), ...($c->avance > 0 ? [$r['avance_plus_tard']] : [])],
            'compteRebours' => true,
            'total' => $c->montantAPayer(),
            'routes' => collect(['show', 'payer', 'statut', 'simuler', 'changer', 'preuve'])
                ->mapWithKeys(fn (string $r) => [$r => route('boutiques.reservation.'.$r, $c->reference)])->all(),
            'nouvelle' => ['url' => route('boutiques'), 'libelle' => __('public.boutiques.titre')],
        ]);
    }

    public function payer(Request $request, string $reference): RedirectResponse
    {
        $reservation = $this->reservation($request, $reference);
        // Caution déjà payée : l'avance se paie depuis son propre écran.
        abort_if($reservation->naturePaiementAttendue() === Paiement::AVANCE, 409);
        $this->demanderPaiement($request, $reservation->load('campagne'));

        return redirect()->route('boutiques.reservation.show', $reservation->reference);
    }

    public function statut(Request $request, string $reference): JsonResponse
    {
        $this->service->expirerReservations();

        return $this->statutPaiement($this->reservation($request, $reference));
    }

    public function simuler(Request $request, string $reference): RedirectResponse
    {
        $reservation = $this->reservation($request, $reference);
        $this->simulerPaiement($request, $reservation);

        return redirect()->route('boutiques.reservation.show', $reservation->reference);
    }

    /** Preuve d'un paiement manuel (virement, versement, espèces) : le paiement passe en attente de contrôle. */
    public function preuve(Request $request, string $reference): RedirectResponse
    {
        $reservation = $this->reservation($request, $reference);
        $this->deposerPreuve($request, $reservation);

        return redirect()->route('boutiques.reservation.show', $reservation->reference)->with('succes', __('public.paiement.manuel.preuve_envoyee'));
    }

    public function changer(Request $request, string $reference): RedirectResponse
    {
        $reservation = $this->reservation($request, $reference);
        $this->abandonnerPaiement($reservation);

        return redirect()->route('boutiques.reservation.show', $reservation->reference);
    }

    /** Reçu de caution (PDF), disponible dès le paiement confirmé. */
    public function recu(Request $request, string $reference, GenerateurPdf $pdf): Response
    {
        $reservation = $this->reservation($request, $reference);
        abort_unless(in_array($reservation->statut, self::PAYEES, true), 404);
        $paiement = Recu::paiement($reservation, Paiement::CAUTION) ?? abort(404);
        $numero = Recu::numero($paiement);

        return response($pdf->rendre('documents.recu-caution', [
            'candidature' => $reservation->load(['campagne', 'boutique.type', 'user']),
            'paiement' => $paiement,
            'numero' => $numero,
        ]), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="recu-caution-'.$numero.'.pdf"',
        ]);
    }

    /** Réservation du citoyen connecté ; celle d'un autre citoyen est refusée. */
    private function reservation(Request $request, string $reference): ReservationBoutique
    {
        $reservation = ReservationBoutique::query()->where('reference', $reference)->firstOrFail();
        abort_unless($reservation->user_id === $request->user()->id, 403);

        return $reservation;
    }
}
