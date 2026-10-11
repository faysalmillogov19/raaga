<?php

namespace App\Http\Controllers\Compte;

use App\Contracts\PaymentProvider;
use App\Enums\CanalPaiement;
use App\Enums\Role;
use App\Enums\StatutPaiement;
use App\Enums\StatutSouscription;
use App\Http\Controllers\Controller;
use App\Models\Souscription;
use App\Services\Paiement\ServicePaiement;
use App\Support\Telephone;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Throwable;

/** /paiement : paiement d'une souscription RÉSERVÉE du citoyen connecté. */
class PaiementController extends Controller
{
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
        $souscription = $this->souscription($request, $reference);

        if ($souscription->statut === StatutSouscription::Payee) {
            return redirect()->route('paiement.confirmation', $souscription->reference);
        }
        abort_unless(in_array($souscription->statut, [StatutSouscription::Reservee, StatutSouscription::Expiree], true), 404);

        $fournisseur = $this->fournisseur();
        $enAttente = ServicePaiement::enAttente($souscription);
        $dernier = $souscription->paiements()->latest('id')->first();

        $etat = match (true) {
            $souscription->statut === StatutSouscription::Expiree => 'expiree',
            ! $fournisseur => 'indisponible',
            $enAttente !== null => 'attente',
            $dernier?->statut === StatutPaiement::Echoue => 'echec',
            default => 'choix',
        };

        return view('public.paiement.show', [
            'souscription' => $souscription->load('tranche'),
            'etat' => $etat,
            'paiement' => $enAttente,
            'simule' => $fournisseur?->estSimule() ?? false,
            'canaux' => $this->canaux($souscription),
            'sequestre' => config('raaga.compte_sequestre'),
        ]);
    }

    public function payer(Request $request, string $reference): RedirectResponse
    {
        $souscription = $this->souscription($request, $reference);
        $canaux = array_map(fn (CanalPaiement $c) => $c->value, $this->canaux($souscription));

        $donnees = $request->validate([
            'canal' => ['required', Rule::in($canaux)],
            'telephone' => [Rule::requiredIf(in_array($request->input('canal'), [CanalPaiement::OrangeMoney->value, CanalPaiement::MoovMoney->value], true)),
                'nullable', 'string', function (string $attribut, mixed $valeur, Closure $echec) {
                    if ($valeur !== null && ! Telephone::estValide($valeur)) {
                        $echec(__('public.paiement.erreurs.telephone'));
                    }
                }],
        ], [], __('public.paiement.attributs'));

        $fournisseur = $this->fournisseur();
        abort_unless($fournisseur, 503);
        $this->service->expirerReservations();
        abort_unless($souscription->fresh()->statut === StatutSouscription::Reservee, 409);

        $canal = CanalPaiement::from($donnees['canal']);
        $mobile = in_array($canal, [CanalPaiement::OrangeMoney, CanalPaiement::MoovMoney], true);
        $this->service->initier($souscription, $canal, $mobile ? Telephone::normaliser($donnees['telephone']) : null, $fournisseur, $request->user());

        return redirect()->route('paiement.show', $souscription->reference);
    }

    /** Suivi de la demande en cours (rafraîchissement automatique de l'écran d'attente). */
    public function statut(Request $request, string $reference): JsonResponse
    {
        $this->service->expirerReservations();
        $souscription = $this->souscription($request, $reference);

        return response()->json([
            'souscription' => $souscription->statut->value,
            'paiement' => $souscription->paiements()->latest('id')->value('statut'),
        ]);
    }

    /** Recette uniquement : choix de l'issue du paiement simulé. */
    public function simuler(Request $request, string $reference): RedirectResponse
    {
        $souscription = $this->souscription($request, $reference);
        $fournisseur = $this->fournisseur();
        abort_unless($fournisseur?->estSimule(), 404);

        $issue = $request->validate(['issue' => ['required', Rule::in(['succes', 'echec'])]])['issue'];
        $paiement = ServicePaiement::enAttente($souscription);
        abort_unless($paiement, 409);

        $this->service->confirmer($paiement->reference, $issue === 'succes', $request->user());

        return redirect()->route('paiement.show', $souscription->reference);
    }

    /** Abandon de la demande en cours pour choisir un autre moyen de paiement. */
    public function changer(Request $request, string $reference): RedirectResponse
    {
        $souscription = $this->souscription($request, $reference);
        $souscription->paiements()->where('statut', StatutPaiement::EnAttente)->update(['statut' => StatutPaiement::Annule]);

        return redirect()->route('paiement.show', $souscription->reference);
    }

    public function confirmation(Request $request, string $reference): View|RedirectResponse
    {
        $souscription = $this->souscription($request, $reference);

        if ($souscription->statut !== StatutSouscription::Payee) {
            return redirect()->route('paiement.show', $souscription->reference);
        }

        return view('public.paiement.confirmation', [
            'souscription' => $souscription,
            'paiement' => $souscription->paiements()->where('statut', StatutPaiement::Confirme)->latest('id')->firstOrFail(),
        ]);
    }

    /** Souscription du citoyen connecté ; celle d'un autre citoyen est refusée. */
    private function souscription(Request $request, string $reference): Souscription
    {
        $souscription = Souscription::query()->where('reference', $reference)->firstOrFail();
        abort_unless($souscription->user_id === $request->user()->id, 403);

        return $souscription;
    }

    /** Fournisseur configuré, ou null s'il refuse de fonctionner (simulateur en production). */
    private function fournisseur(): ?PaymentProvider
    {
        try {
            return app(PaymentProvider::class);
        } catch (Throwable) {
            return null;
        }
    }

    /** @return list<CanalPaiement> canaux actifs de la tranche (paramètres confirmés) */
    private function canaux(Souscription $souscription): array
    {
        return array_values(array_filter(array_map(
            fn (string $c) => CanalPaiement::tryFrom($c),
            (array) $souscription->tranche->canaux_actifs,
        )));
    }
}
