<?php

namespace App\Http\Controllers\Compte\Concerns;

use App\Contracts\Payable;
use App\Contracts\PaymentProvider;
use App\Enums\StatutPaiement;
use App\Models\MoyenPaiement;
use App\Services\Audit\Journal;
use App\Services\Kyc\VerificationIdentite;
use App\Services\Paiement\OuverturePaiements;
use App\Services\Paiement\ServicePaiement;
use App\Support\CompteSequestre;
use App\Support\Telephone;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
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
        $fournisseur = $this->fournisseur($objet);
        $enAttente = ServicePaiement::enAttente($objet);
        $dernier = $objet->paiements()->latest('id')->first();
        $canaux = $this->canauxProposes($objet, $fournisseur);

        return view('public.paiement.show', [
            ...$contexte,
            'objet' => $objet,
            'etat' => match (true) {
                $objet->estExpire() => 'expiree',
                $enAttente?->enControle() => 'controle',
                $enAttente?->estManuel() => 'manuel',
                $canaux === [] => 'indisponible',
                $enAttente !== null => 'attente',
                $dernier?->statut === StatutPaiement::Echoue => 'echec',
                default => 'choix',
            },
            'paiement' => $enAttente,
            'dernier' => $dernier,
            'simule' => $fournisseur?->estSimule() ?? false,
            'canaux' => $canaux,
            'sequestre' => CompteSequestre::pour($objet->compteDestination()),
            'compte' => $objet->compteDestination(),
        ]);
    }

    /**
     * Moyens proposés : seulement ceux réellement ouverts. Paiements manuels (virement, versement, espèces) : aucun
     * opérateur, il suffit du compte renseigné ; les autres exigent un fournisseur réel.
     *
     * @return list<MoyenPaiement>
     */
    private function canauxProposes(Payable $objet, ?PaymentProvider $fournisseur): array
    {
        return array_values(array_filter($objet->canauxPaiement(), fn (MoyenPaiement $c) => OuverturePaiements::canalOuvert($c, $objet->compteDestination())
            && ($c->estManuel() || $fournisseur !== null)));
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

        // Moyen fermé (production : compte non renseigné, ou opérateur absent pour un paiement par opérateur) : rien ne démarre.
        $canal = MoyenPaiement::parCode($donnees['canal']);
        if (! OuverturePaiements::canalOuvert($canal, $objet->compteDestination())) {
            throw ValidationException::withMessages(['canal' => __('public.paiement.indisponible_titre')]);
        }
        $this->service->expirerReservations();
        abort_unless($objet->fresh()->attendPaiement(), 409);
        abort_if($objet->paiements()->enControle()->exists(), 409);

        // Virement, versement, espèces : aucun opérateur ; référence de paiement unique, puis preuve jointe.
        if ($canal->estManuel()) {
            $this->service->initierManuel($objet, $canal, $request->user());

            return;
        }
        $fournisseur = $this->fournisseur($objet);
        if ($fournisseur === null) {
            throw ValidationException::withMessages(['canal' => __('public.paiement.indisponible_titre')]);
        }
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
        // Preuve en cours de contrôle : le paiement ne peut plus être abandonné.
        abort_if($objet->paiements()->enControle()->exists(), 409);
        $objet->paiements()->where('statut', StatutPaiement::EnAttente)->update(['statut' => StatutPaiement::Annule]);
    }

    /**
     * Preuve d'un paiement manuel (avis de virement, bordereau ou reçu de caisse : image ou PDF, 5 Mo au plus), jointe
     * avant la fin du délai. Le paiement passe « en attente de contrôle » : délai gelé jusqu'à la décision du contrôleur.
     */
    protected function deposerPreuve(Request $request, Payable&Model $objet): void
    {
        $fichier = $request->validate([
            'preuve' => ['required', 'file', 'mimes:jpg,jpeg,png,pdf', 'mimetypes:image/jpeg,image/png,application/pdf', 'max:5120'],
        ], ['preuve.max' => __('public.paiement.manuel.erreurs.taille'), 'preuve.mimes' => __('public.paiement.manuel.erreurs.format'),
            'preuve.mimetypes' => __('public.paiement.manuel.erreurs.format')], ['preuve' => __('public.paiement.manuel.preuve')])['preuve'];

        $paiement = ServicePaiement::enAttente($objet);
        if ($paiement === null || ! $paiement->estManuel() || $paiement->enControle()) {
            throw ValidationException::withMessages(['preuve' => __('public.paiement.manuel.erreurs.aucun_paiement')]);
        }
        if (! $objet->attendPaiement() || $objet->expire_le->isPast()) {
            throw ValidationException::withMessages(['preuve' => __('public.paiement.manuel.erreurs.delai')]);
        }

        $chemin = $fichier->storeAs('paiements/'.$objet->reference, Str::uuid().'.'.$fichier->extension(), VerificationIdentite::DISQUE);
        $paiement->forceFill(['preuve_chemin' => $chemin, 'preuve_nom' => Str::limit($fichier->getClientOriginalName(), 200, ''),
            'preuve_mime' => $fichier->getMimeType(), 'preuve_deposee_le' => now()])->save();
        app(Journal::class)->ecrire($request->user(), 'paiement.preuve_deposee', 'Paiement', $paiement->id, null,
            ['objet' => $objet->reference, 'reference_versement' => $paiement->reference_versement]);
    }

    /** Fournisseur configuré, ou null s'il refuse de fonctionner (simulateur en production). */
    protected function fournisseur(?Payable $objet = null): ?PaymentProvider
    {
        if ($objet !== null && ! OuverturePaiements::operateurOuvert($objet->compteDestination())) {
            return null;
        }
        try {
            return app(PaymentProvider::class);
        } catch (Throwable) {
            return null;
        }
    }
}
