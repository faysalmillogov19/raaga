<?php

namespace App\Http\Controllers\Admin;

use App\Enums\Droit;
use App\Enums\TypeCarte;
use App\Http\Controllers\Controller;
use App\Models\Carte;
use App\Models\KycDocument;
use App\Models\User;
use App\Services\Cartes\Cartes;
use App\Services\Cartes\FichiersCarte;
use App\Services\Cartes\PhotosPersonnel;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

/**
 * Gestion des cartes d'accès. ADMIN : demande de révocation (motif), réémission après une perte (à la demande du
 * titulaire, nouveau numéro et nouveau QR code), validation des photos du personnel. VALIDATEUR : confirme ou rejette la
 * révocation demandée (depuis « À valider »). SUPER_ADMIN et agent de guichet : relance de la génération des fichiers d'une
 * carte en échec définitif (alerte du tableau de bord), tracée au journal d'audit.
 */
class CartesController extends Controller
{
    public function __construct(private readonly Cartes $cartes, private readonly PhotosPersonnel $photos) {}

    public function demanderRevocation(Request $request, Carte $carte): RedirectResponse
    {
        Gate::authorize(Droit::CartesGerer->value);
        $motif = $request->validate(['motif' => ['required', 'string', 'min:5', 'max:500']], ['motif.*' => __('admin.cartes.erreurs.motif')])['motif'];
        if (! $carte->courante || $carte->revocation_demandee_le) {
            return back()->with('erreur', __('admin.cartes.erreurs.deja_demandee'));
        }
        $this->cartes->demanderRevocation($carte, $motif, $request->user());

        return redirect()->route('admin.guichet.titulaire', $carte->user_id)->avecSucces('admin.cartes.messages.revocation_demandee');
    }

    /** Perte : l'ancienne carte est révoquée tout de suite, la nouvelle a un nouveau numéro et un nouveau QR code. */
    public function reemettre(Request $request, User $titulaire, string $type): RedirectResponse
    {
        Gate::authorize(Droit::CartesGerer->value);
        $type = TypeCarte::from(strtoupper($type));
        $courante = Cartes::courante($titulaire, $type);
        abort_unless($courante || Cartes::revoqueeManuellement($titulaire, $type), 404);
        $nouvelle = $this->cartes->reemettre($titulaire, $type, $request->user(), $courante ? 'perte' : 'reemission');

        return redirect()->route('admin.guichet.titulaire', $titulaire)->avecSucces('admin.cartes.messages.reemise', ['numero' => $nouvelle->numero]);
    }

    public function relancerFichiers(Request $request, Carte $carte, FichiersCarte $fichiers): RedirectResponse
    {
        Gate::authorize(Droit::CartesFichiersRelancer->value);
        if (! $carte->fichiersEnEchec()) {
            return back()->with('erreur', __('admin.cartes.erreurs.pas_en_echec'));
        }
        $fichiers->relancer($carte, $request->user());

        return back()->avecSucces('admin.cartes.messages.fichiers_relances', ['numero' => $carte->numero]);
    }

    public function confirmer(Request $request, Carte $carte): RedirectResponse
    {
        Gate::authorize(Droit::CartesRevocationConfirmer->value);
        $this->cartes->confirmerRevocation($carte, $request->user());

        return redirect()->route('admin.a-valider.rubrique', 'cartes')->avecSucces('admin.cartes.messages.revocation_confirmee');
    }

    public function rejeter(Request $request, Carte $carte): RedirectResponse
    {
        Gate::authorize(Droit::CartesRevocationConfirmer->value);
        $motif = $request->validate(['motif' => ['required', 'string', 'min:5', 'max:1000']], ['motif.*' => __('admin.utilisateurs.erreurs.motif')])['motif'];
        $this->cartes->rejeterRevocation($carte, $request->user(), $motif);

        return redirect()->route('admin.a-valider.rubrique', 'cartes')->avecSucces('admin.cartes.messages.revocation_rejetee');
    }

    /** Photos du personnel en attente de validation. */
    public function photos(): View
    {
        Gate::authorize(Droit::CartesGerer->value);

        return view('admin.cartes.photos', ['photos' => PhotosPersonnel::enAttente()->with('user.role')->oldest('id')->get()]);
    }

    public function validerPhoto(Request $request, KycDocument $document): RedirectResponse
    {
        Gate::authorize(Droit::CartesGerer->value);
        abort_unless(PhotosPersonnel::enAttente()->whereKey($document->id)->exists(), 404);
        $this->photos->valider($document, $request->user());

        return redirect()->route('admin.cartes.photos')->avecSucces('admin.cartes.messages.photo_validee');
    }

    public function refuserPhoto(Request $request, KycDocument $document): RedirectResponse
    {
        Gate::authorize(Droit::CartesGerer->value);
        abort_unless(PhotosPersonnel::enAttente()->whereKey($document->id)->exists(), 404);
        $motif = $request->validate(['motif' => ['required', 'string', 'min:5', 'max:500']], ['motif.*' => __('admin.cartes.erreurs.motif')])['motif'];
        $this->photos->refuser($document, $request->user(), $motif);

        return redirect()->route('admin.cartes.photos')->avecSucces('admin.cartes.messages.photo_refusee');
    }
}
