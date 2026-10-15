<?php

namespace App\Http\Controllers\Admin\Concerns;

use App\Services\Validation\SoumissionGroupee;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Fenêtres et formulaires d'ajout ou de modification : « Enregistrer et soumettre » (bouton principal, apres=soumettre)
 * ou « Enregistrer en brouillon » (apres=brouillon). Après l'enregistrement, l'action « Soumettre » de la fiche est
 * appelée telle quelle ; si son contrôle échoue, l'élément reste en brouillon et la fenêtre (ou le formulaire) se
 * rouvre avec la raison sous le champ concerné.
 */
trait EnregistreEtSoumet
{
    protected function soumissionDemandee(Request $request): bool
    {
        return $request->input('apres') === 'soumettre';
    }

    /**
     * @param  RedirectResponse  $reponse  réponse de l'enregistrement (message déjà en session)
     * @param  string  $echec  adresse où la raison s'affiche (fiche ou formulaire de l'élément)
     * @param  string|null  $fenetre  fenêtre de modification à rouvrir sur cette adresse
     */
    protected function puisSoumettre(Request $request, RedirectResponse $reponse, string $entite, Model $ligne, string $echec, ?string $fenetre = null): RedirectResponse
    {
        // Enregistrement refusé (message d'erreur ou champs signalés par cette requête) : rien à soumettre.
        $nouveaux = $request->session()->get('_flash.new', []);
        if (! $this->soumissionDemandee($request) || in_array('erreur', $nouveaux, true) || in_array('errors', $nouveaux, true)) {
            return $reponse;
        }

        $raison = app(SoumissionGroupee::class)->soumettreUn($request, $entite, $ligne->fresh());
        if ($raison === null) {
            return $reponse->with('succes', __('admin.soumission.enregistre_soumis'));
        }

        return redirect()->to($echec)
            ->withErrors([SoumissionGroupee::champ($raison) => $raison])
            ->withInput($fenetre ? ['_fenetre' => $fenetre] : [])
            ->with('succes', __('admin.soumission.brouillon_conserve'));
    }
}
