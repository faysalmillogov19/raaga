<?php

namespace App\Http\Controllers\Compte;

use App\Enums\Role;
use App\Enums\StatutSouscription;
use App\Http\Controllers\Compte\Concerns\GerePaiements;
use App\Http\Controllers\Controller;
use App\Models\Souscription;
use App\Services\Documents\GenerateurPdf;
use App\Services\Paiement\ServicePaiement;
use App\Support\Recu;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\View\View;

/** /paiement : paiement d'une souscription RÉSERVÉE du citoyen connecté. */
class PaiementController extends Controller
{
    use GerePaiements;

    public function __construct(private readonly ServicePaiement $service) {}

    /** Sans réservation en cours : état vide ; sinon, ouverture de la plus récente. */
    public function index(Request $request): View|RedirectResponse
    {
        $user = $request->user();

        if ($user?->hasRole(Role::Citoyen)) {
            $this->service->expirerReservations();
            $reservee = $user->souscriptions()->where('statut', StatutSouscription::Reservee)->latest('id')->first();

            if ($reservee) {
                return redirect()->route('paiement.show', $reservee->reference);
            }
        }

        return view('public.preparation', ['page' => 'paiement', 'titre' => __('public.preparation.pages.paiement.titre')]);
    }

    public function show(Request $request, string $reference): View|RedirectResponse
    {
        $this->service->expirerReservations();
        $s = $this->souscription($request, $reference);

        if (Recu::disponible($s)) {
            return redirect()->route('paiement.confirmation', $s->reference);
        }
        abort_unless(in_array($s->statut, [StatutSouscription::Reservee, StatutSouscription::Expiree], true), 404);

        return $this->ecranPaiement($s->load('tranche'), [
            'titre' => __('public.paiement.titre'),
            'fil' => [['libelle' => __('public.preparation.pages.souscrire.titre'), 'url' => route('souscrire')], ['libelle' => __('public.paiement.titre')]],
            'alerte' => session('souscription.reservee')
                ? __('public.souscription.reservee', ['reference' => '<span class="whitespace-nowrap font-bold">'.e($s->reference).'</span>'])
                : null,
            'lignes' => [
                [__('public.souscription.recap_actions'), format_nombre($s->nb_actions_demandees)],
                [__('public.souscription.recap_montant'), format_fcfa($s->montant)],
                [__('public.souscription.recap_frais'), format_fcfa($s->frais_souscription)],
            ],
            'total' => $s->montant + $s->frais_souscription,
            'routes' => $this->routes($s),
            'nouvelle' => ['url' => route('souscrire'), 'libelle' => __('public.paiement.nouvelle_souscription')],
        ]);
    }

    public function payer(Request $request, string $reference): RedirectResponse
    {
        $souscription = $this->souscription($request, $reference);
        $this->demanderPaiement($request, $souscription->load('tranche'));

        return redirect()->route('paiement.show', $souscription->reference);
    }

    public function statut(Request $request, string $reference): JsonResponse
    {
        $this->service->expirerReservations();

        return $this->statutPaiement($this->souscription($request, $reference));
    }

    public function simuler(Request $request, string $reference): RedirectResponse
    {
        $souscription = $this->souscription($request, $reference);
        $this->simulerPaiement($request, $souscription);

        return redirect()->route('paiement.show', $souscription->reference);
    }

    /** Preuve d'un paiement manuel (virement, versement, espèces) : le paiement passe en attente de contrôle. */
    public function preuve(Request $request, string $reference): RedirectResponse
    {
        $souscription = $this->souscription($request, $reference);
        $this->deposerPreuve($request, $souscription);

        return redirect()->route('paiement.show', $souscription->reference)->avecSucces('public.paiement.manuel.preuve_envoyee');
    }

    public function changer(Request $request, string $reference): RedirectResponse
    {
        $souscription = $this->souscription($request, $reference);
        $this->abandonnerPaiement($souscription);

        return redirect()->route('paiement.show', $souscription->reference);
    }

    public function confirmation(Request $request, string $reference): View|RedirectResponse
    {
        $souscription = $this->souscription($request, $reference);

        if (! Recu::disponible($souscription)) {
            return redirect()->route('paiement.show', $souscription->reference);
        }
        $paiement = Recu::paiement($souscription) ?? abort(404);

        return view('public.paiement.confirmation', [
            'souscription' => $souscription,
            'paiement' => $paiement,
            'numeroRecu' => Recu::numero($paiement),
        ]);
    }

    /** Reçu PDF : téléchargeable tant que la souscription est payée ou allouée (repris dans Mon espace). */
    public function recu(Request $request, string $reference, GenerateurPdf $pdf): Response
    {
        $souscription = $this->souscription($request, $reference);
        abort_unless(Recu::disponible($souscription), 404);
        $paiement = Recu::paiement($souscription) ?? abort(404);
        $numero = Recu::numero($paiement);

        return response($pdf->rendre('documents.recu-souscription', [
            'souscription' => $souscription->load(['tranche', 'user']),
            'paiement' => $paiement,
            'numero' => $numero,
        ]), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="recu-'.$numero.'.pdf"',
        ]);
    }

    /** @return array<string, string> */
    private function routes(Souscription $s): array
    {
        return collect(['show', 'payer', 'statut', 'simuler', 'changer', 'preuve'])
            ->mapWithKeys(fn (string $r) => [$r => route('paiement.'.$r, $s->reference)])->all();
    }

    /** Souscription du citoyen connecté ; celle d'un autre citoyen est refusée. */
    private function souscription(Request $request, string $reference): Souscription
    {
        $souscription = Souscription::query()->where('reference', $reference)->firstOrFail();
        abort_unless($souscription->user_id === $request->user()->id, 403);

        return $souscription;
    }
}
