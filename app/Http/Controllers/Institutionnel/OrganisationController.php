<?php

namespace App\Http\Controllers\Institutionnel;

use App\Enums\Role as RoleEnum;
use App\Enums\TypeDocumentKyb;
use App\Models\User;
use App\Services\Audit\Journal;
use App\Services\Backoffice\RegleGestionException;
use App\Services\Institutionnels\DossierKyb;
use App\Services\Institutionnels\GestionInstitutions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * « Mon organisation » : dossier KYB, RIB et utilisateurs de l'institution. Toute modification
 * est réservée au signataire ; le lecteur consulte.
 */
class OrganisationController extends Controleur
{
    public function __construct(
        private readonly DossierKyb $dossier,
        private readonly GestionInstitutions $gestion,
    ) {}

    public function show(): View
    {
        $institution = $this->institution();

        return view('institutionnel.organisation', [
            'institution' => $institution,
            'pieces' => $institution->piecesEnVigueur(),
            'utilisateurs' => $institution->utilisateurs()->orderBy('name')->get(),
        ]);
    }

    public function deposer(Request $request): RedirectResponse
    {
        $this->exigerSignataire();
        $donnees = $request->validate([
            'type' => ['required', Rule::enum(TypeDocumentKyb::class)],
            'fichier' => ['required', 'file', 'mimes:pdf', 'max:10240'],
        ], [], ['fichier' => __('institutionnel.organisation.dossier')]);

        return $this->executer(fn () => $this->dossier->deposer($request->user(), TypeDocumentKyb::from($donnees['type']), $request->file('fichier')),
            'institutionnel.organisation.depose');
    }

    public function rib(Request $request, Journal $journal): RedirectResponse
    {
        $this->exigerSignataire();
        $donnees = $request->validate([
            'rib_titulaire' => ['required', 'string', 'max:200'],
            'rib_banque' => ['required', 'string', 'max:120'],
            'rib_numero' => ['required', 'string', 'max:60'],
        ], [], [
            'rib_titulaire' => __('institutionnel.organisation.rib_titulaire'),
            'rib_banque' => __('institutionnel.organisation.rib_banque'),
            'rib_numero' => __('institutionnel.organisation.rib_numero'),
        ]);

        $institution = $this->institution();
        $avant = $institution->only(array_keys($donnees));
        $institution->forceFill($donnees)->save();
        $journal->ecrire($request->user(), 'institution.rib_modifie', 'Institution', $institution->id, $avant, $donnees);

        return redirect()->route('institutionnel.organisation')->avecSucces('institutionnel.organisation.rib_enregistre');
    }

    public function inviter(Request $request): RedirectResponse
    {
        $this->exigerSignataire();
        $donnees = $request->validate([
            'nom' => ['required', 'string', 'max:150'],
            'email' => ['required', 'email', 'max:255'],
            'role' => ['required', Rule::in(array_column(RoleEnum::institutionnels(), 'value'))],
        ], [], ['nom' => __('institutionnel.organisation.nom'), 'email' => __('institutionnel.organisation.email')]);

        return $this->executer(fn () => $this->gestion->inviter($request->user(), $this->institution(), $donnees['nom'], $donnees['email'], RoleEnum::from($donnees['role'])),
            __('institutionnel.organisation.invite', ['email' => $donnees['email']]));
    }

    public function desactiver(Request $request, User $utilisateur): RedirectResponse
    {
        $this->exigerSignataire();
        $this->verifierAppartenance($utilisateur);
        $motif = $this->motif($request);

        return $this->executer(fn () => $this->gestion->desactiverUtilisateur($request->user(), $utilisateur, $motif), 'institutionnel.organisation.desactive');
    }

    public function reactiver(Request $request, User $utilisateur): RedirectResponse
    {
        $this->exigerSignataire();
        $this->verifierAppartenance($utilisateur);
        $motif = $this->motif($request);

        return $this->executer(fn () => $this->gestion->reactiverUtilisateur($request->user(), $utilisateur, $motif), 'institutionnel.organisation.reactive');
    }

    private function motif(Request $request): string
    {
        return $request->validate(['motif' => ['required', 'string', 'min:5', 'max:500']], ['motif.*' => __('admin.utilisateurs.erreurs.motif')])['motif'];
    }

    private function executer(\Closure $action, string $message): RedirectResponse
    {
        try {
            $action();
        } catch (RegleGestionException $e) {
            return redirect()->route('institutionnel.organisation')->with('erreur', $e->getMessage());
        }

        return redirect()->route('institutionnel.organisation')->avecSucces($message);
    }
}
