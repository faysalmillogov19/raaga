<?php

namespace App\Http\Controllers\Admin;

use App\Enums\Droit;
use App\Enums\Role;
use App\Enums\StatutKyc;
use App\Http\Controllers\Controller;
use App\Models\ReservationBoutique;
use App\Models\User;
use App\Models\VerificationKyc;
use App\Services\Kyc\DossiersIdentite;
use App\Services\Kyc\VerificationIdentite;
use App\Support\Tableau\Colonne;
use App\Support\Tableau\Filtre;
use App\Support\Tableau\ResultatTableau;
use App\Support\Tableau\Tableau;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * « Vérifications d'identité » : l'ADMIN voit la pièce (CNIB ou passeport) et marque le dossier conforme ou douteux,
 * avec un motif ; un dossier douteux devient une proposition d'annulation que le VALIDATEUR confirme ou rejette
 * (« À valider › Identités »). Aucun montant n'apparaît sur ces écrans.
 */
class VerificationsIdentiteController extends Controller
{
    public function __construct(private readonly DossiersIdentite $dossiers, private readonly VerificationIdentite $verification) {}

    public function index(Request $request): Response|StreamedResponse|View
    {
        Gate::authorize(Droit::KycVoir->value);
        $c = __('admin.kyc.colonnes');
        $requete = User::query()->whereHas('role', fn ($q) => $q->where('code', Role::Citoyen->value))
            ->where('statut_kyc', '!=', StatutKyc::NonSoumis)->whereNotNull('statut_kyc')
            ->withMax('kycDocuments', 'created_at');

        return Tableau::pour($requete, 'kyc')
            ->colonnes([
                Colonne::make('name', $c['citoyen'])->triable()->recherchable()->classe('font-semibold'),
                Colonne::make('telephone', $c['telephone'])->recherchable()->classe('whitespace-nowrap')->valeur(fn (User $u) => $u->telephone),
                Colonne::make('depose', $c['depose'])->triable('kyc_documents_max_created_at')->classe('whitespace-nowrap')
                    ->valeur(fn (User $u) => $u->kyc_documents_max_created_at ? Carbon::parse($u->kyc_documents_max_created_at)->format('d/m/Y H:i') : null),
                Colonne::make('statut_kyc', $c['statut'])->triable()->valeur(fn (User $u) => __('admin.kyc.statuts.'.$u->statut_kyc->value)),
                Colonne::make('proposition', $c['proposition'])->valeur(fn (User $u) => DossiersIdentite::propositionEnAttente($u) ? __('admin.kyc.proposition_en_attente') : null),
                Colonne::make('actions', $c['actions'])->vue('admin.kyc._actions', 'citoyen')->exportable(false),
            ])
            ->filtres([Filtre::make('statut_kyc', $c['statut'], collect([StatutKyc::EnAttente, StatutKyc::Valide, StatutKyc::Rejete])
                ->mapWithKeys(fn (StatutKyc $s) => [$s->value => __('admin.kyc.statuts.'.$s->value)])->all(), 'statut_kyc')])
            ->triParDefaut('depose', 'asc')
            ->unite(__('admin.kyc.unite'))
            ->repondre($request, fn (ResultatTableau $t) => view('admin.kyc.index', ['tableau' => $t]));
    }

    public function dossier(User $citoyen): View
    {
        Gate::authorize(Droit::KycVoir->value);
        abort_unless($citoyen->hasRole(Role::Citoyen), 404);

        return view('admin.kyc.dossier', [
            'citoyen' => $citoyen,
            'pieces' => $this->verification->dossier($citoyen)->filter(),
            'historique' => VerificationKyc::query()->where('user_id', $citoyen->id)->with(['auteur'])->latest('id')->get(),
            'proposition' => DossiersIdentite::propositionEnAttente($citoyen),
            // Références et statuts seulement : aucun montant pour l'ADMIN.
            'souscriptions' => $citoyen->souscriptions()->latest('id')->get(['id', 'reference', 'statut']),
            'candidatures' => ReservationBoutique::query()->where('user_id', $citoyen->id)->latest('id')->get(['id', 'reference', 'statut']),
        ]);
    }

    public function conforme(Request $request, User $citoyen): RedirectResponse
    {
        Gate::authorize(Droit::KycTraiter->value);
        $motif = $request->validate(['motif' => ['nullable', 'string', 'max:1000']], [], __('admin.kyc.champs'))['motif'] ?? null;
        $this->dossiers->conforme($citoyen, $request->user(), $motif);

        return redirect()->route('admin.kyc.dossier', $citoyen)->with('succes', __('admin.kyc.messages.conforme'));
    }

    public function douteux(Request $request, User $citoyen): RedirectResponse
    {
        Gate::authorize(Droit::KycTraiter->value);
        $motif = $request->validate(['motif' => ['required', 'string', 'min:5', 'max:1000']], [], __('admin.kyc.champs'))['motif'];
        $this->dossiers->douteux($citoyen, $request->user(), $motif);

        return redirect()->route('admin.kyc.dossier', $citoyen)->with('succes', __('admin.kyc.messages.douteux'));
    }

    public function confirmerAnnulation(Request $request, VerificationKyc $verification): RedirectResponse
    {
        Gate::authorize(Droit::KycAnnulationConfirmer->value);
        $this->dossiers->confirmerAnnulation($verification, $request->user());

        return redirect()->route('admin.a-valider.rubrique', 'identites')->with('succes', __('admin.kyc.messages.annulation_confirmee'));
    }

    public function rejeterAnnulation(Request $request, VerificationKyc $verification): RedirectResponse
    {
        Gate::authorize(Droit::KycAnnulationConfirmer->value);
        $motif = $request->validate(['motif' => ['required', 'string', 'min:5', 'max:1000']], ['motif.*' => __('admin.utilisateurs.erreurs.motif')])['motif'];
        $this->dossiers->rejeterAnnulation($verification, $request->user(), $motif);

        return redirect()->route('admin.a-valider.rubrique', 'identites')->with('succes', __('admin.kyc.messages.annulation_rejetee'));
    }
}
