<?php

namespace App\Http\Controllers\Compte\Concerns;

use App\Contracts\Payable;
use App\Contracts\PaymentProvider;
use App\Enums\StatutPaiement;
use App\Models\MoyenPaiement;
use App\Services\Paiement\ServicePaiement;
use App\Support\CompteSequestre;
use App\Support\Telephone;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Throwable;

/**
 * Écran et actions de paiement communs à une souscription d'actions et à la caution d'une candidature
 * aux boutiques : choix du canal, attente (reprise), échec, délai dépassé, simulateur de recette.
 */
trait GerePaiements
{
    /**
     * @param  array{titre: string, fil: list<array{libelle: string, url?: string}>, lignes: list<array{0: string, 1: string}>, total: int,
     *               routes: array<string, string>, nouvelle: array{url: string, libelle: string}, alerte?: ?string}  $contexte
     */
    protected function ecranPaiement(Payable&Model $objet, array $contexte): View
    {
        $fournisseur = $this->fournisseur();
        $enAttente = ServicePaiement::enAttente($objet);
        $dernier = $objet->paiements()->latest('id')->first();

        return view('public.paiement.show', [
            ...$contexte,
            'objet' => $objet,
            'etat' => match (true) {
                $objet->estExpire() => 'expiree',
                ! $fournisseur => 'indisponible',
                $enAttente !== null => 'attente',
                $dernier?->statut === StatutPaiement::Echoue => 'echec',
                default => 'choix',
            },
            'paiement' => $enAttente,
            'simule' => $fournisseur?->estSimule() ?? false,
            'canaux' => $objet->canauxPaiement(),
            'sequestre' => CompteSequestre::pour($objet->compteDestination()),
        ]);
    }

    protected function demanderPaiement(Request $request, Payable&Model $objet): void
    {
        $canaux = array_map(fn (MoyenPaiement $c) => $c->code, $objet->canauxPaiement());

        $donnees = $request->validate([
            'canal' => ['required', Rule::in($canaux)],
            'telephone' => [Rule::requiredIf(fn () => MoyenPaiement::parCode((string) $request->input('canal'))?->estMobile() ?? false),
                'nullable', 'string', function (string $attribut, mixed $valeur, Closure $echec) {
                    if ($valeur !== null && ! Telephone::estValide($valeur)) {
                        $echec(__('public.paiement.erreurs.telephone'));
                    }
                }],
        ], [], __('public.paiement.attributs'));

        $fournisseur = $this->fournisseur();
        abort_unless($fournisseur, 503);
        $this->service->expirerReservations();
        abort_unless($objet->fresh()->attendPaiement(), 409);

        $canal = MoyenPaiement::parCode($donnees['canal']);
        $mobile = $canal->estMobile();
        $this->service->initier($objet, $canal, $mobile ? Telephone::normaliser($donnees['telephone']) : null, $fournisseur, $request->user());
    }

    protected function statutPaiement(Payable&Model $objet): JsonResponse
    {
        return response()->json([
            'objet' => $objet->statut->value,
            'paiement' => $objet->paiements()->latest('id')->value('statut'),
        ]);
    }

    /** Recette uniquement : choix de l'issue du paiement simulé. */
    protected function simulerPaiement(Request $request, Payable $objet): void
    {
        abort_unless($this->fournisseur()?->estSimule(), 404);

        $issue = $request->validate(['issue' => ['required', Rule::in(['succes', 'echec'])]])['issue'];
        $paiement = ServicePaiement::enAttente($objet);
        abort_unless($paiement, 409);

        $this->service->confirmer($paiement->reference, $issue === 'succes', $request->user());
    }

    /** Abandon de la demande en cours pour choisir un autre moyen de paiement. */
    protected function abandonnerPaiement(Payable $objet): void
    {
        $objet->paiements()->where('statut', StatutPaiement::EnAttente)->update(['statut' => StatutPaiement::Annule]);
    }

    /** Fournisseur configuré, ou null s'il refuse de fonctionner (simulateur en production). */
    protected function fournisseur(): ?PaymentProvider
    {
        try {
            return app(PaymentProvider::class);
        } catch (Throwable) {
            return null;
        }
    }
}
