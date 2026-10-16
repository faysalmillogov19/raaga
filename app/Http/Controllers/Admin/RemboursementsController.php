<?php

namespace App\Http\Controllers\Admin;

use App\Enums\Droit;
use App\Http\Controllers\Controller;
use App\Models\Remboursement;
use App\Services\Paiement\Remboursements;
use App\Support\Tableau\Colonne;
use App\Support\Tableau\Filtre;
use App\Support\Tableau\ResultatTableau;
use App\Support\Tableau\Tableau;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Remboursements : liste (CONTRÔLEUR DES PAIEMENTS, VALIDATEUR, RÉGULATEUR en lecture). Le contrôleur déclare
 * l'exécution (mode, référence) ; le VALIDATEUR la confirme ou la rejette dans « À valider › Paiements ».
 */
class RemboursementsController extends Controller
{
    public function __construct(private readonly Remboursements $remboursements) {}

    public function index(Request $request): Response|StreamedResponse|View
    {
        Gate::authorize(Droit::RemboursementsVoir->value);
        $c = __('admin.remboursements.colonnes');

        return Tableau::pour(Remboursement::query()->with(['user', 'souscription', 'reservation']), 'remboursements')
            ->colonnes([
                Colonne::make('reference', $c['reference'])->triable()->recherchable()->classe('font-mono font-semibold'),
                Colonne::make('date', $c['date'])->triable('created_at')->classe('whitespace-nowrap')->valeur(fn (Remboursement $r) => $r->created_at->format('d/m/Y')),
                Colonne::make('citoyen', $c['citoyen'])->recherchable(fn ($q, $terme) => $q->whereHas('user', fn ($u) => $u->whereContient('name', $terme)))
                    ->valeur(fn (Remboursement $r) => $r->user?->name),
                Colonne::make('objet', $c['objet'])->valeur(fn (Remboursement $r) => $r->objet()?->reference)->classe('font-mono'),
                Colonne::make('origine', $c['origine'])->valeur(fn (Remboursement $r) => __('admin.remboursements.origines.'.$r->origine)),
                Colonne::make('montant', $c['montant'])->numerique()->triable()->valeur(fn (Remboursement $r) => format_fcfa($r->montant))->texte(fn (Remboursement $r) => $r->montant),
                Colonne::make('statut', $c['statut'])->triable()->valeur(fn (Remboursement $r) => $r->libelleStatut()),
                Colonne::make('execution', $c['execution'])->valeur(fn (Remboursement $r) => $r->reference_operation ? $r->mode.' · '.$r->reference_operation : null),
                Colonne::make('actions', $c['actions'])->vue('admin.remboursements._actions', 'remboursement')->exportable(false),
            ])
            ->filtres([Filtre::make('statut', $c['statut'], collect([Remboursement::EN_ATTENTE_RETENUE, Remboursement::A_EFFECTUER, Remboursement::PROPOSE, Remboursement::EFFECTUE, Remboursement::RETENUE_TOTALE])
                ->mapWithKeys(fn ($s) => [$s => __('admin.remboursements.statuts.'.$s)])->all(), 'statut')])
            ->triParDefaut('date', 'desc')
            ->unite(__('admin.remboursements.unite'))
            ->export('Raaga_remboursements')
            ->repondre($request, fn (ResultatTableau $t) => view('admin.remboursements.index', ['tableau' => $t]));
    }

    public function declarer(Request $request, Remboursement $remboursement): RedirectResponse
    {
        Gate::authorize(Droit::RemboursementsSuivre->value);
        $donnees = $request->validate([
            'mode' => ['required', 'string', 'max:40'],
            'reference_operation' => ['required', 'string', 'max:100'],
        ], [], __('admin.remboursements.champs'));
        $this->remboursements->declarerExecution($remboursement, $donnees['mode'], $donnees['reference_operation'], $request->user());

        return redirect()->route('admin.remboursements')->with('succes', __('admin.remboursements.messages.declare'));
    }

    public function confirmer(Request $request, Remboursement $remboursement): RedirectResponse
    {
        Gate::authorize(Droit::RemboursementsApprouver->value);
        $this->remboursements->confirmer($remboursement, $request->user());

        return redirect()->route('admin.remboursements')->with('succes', __('admin.remboursements.messages.confirme'));
    }

    public function rejeter(Request $request, Remboursement $remboursement): RedirectResponse
    {
        Gate::authorize(Droit::RemboursementsApprouver->value);
        $motif = $request->validate(['motif' => ['required', 'string', 'min:5', 'max:1000']], ['motif.*' => __('admin.utilisateurs.erreurs.motif')])['motif'];
        $this->remboursements->rejeter($remboursement, $request->user(), $motif);

        return redirect()->route('admin.remboursements')->with('succes', __('admin.remboursements.messages.rejete'));
    }
}
