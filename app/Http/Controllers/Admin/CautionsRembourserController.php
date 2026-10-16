<?php

namespace App\Http\Controllers\Admin;

use App\Enums\Droit;
use App\Http\Controllers\Controller;
use App\Models\DecisionAvance;
use App\Models\Remboursement;
use App\Services\Boutiques\AvancesBoutiques;
use App\Support\Tableau\Colonne;
use App\Support\Tableau\ResultatTableau;
use App\Support\Tableau\Tableau;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Cautions à rembourser : réservations annulées automatiquement faute d'avance, dont le remboursement de la caution
 * attend la retenue. Le RÉGULATEUR propose la retenue (0 à 100 %, motif obligatoire) ; le VALIDATEUR la confirme ou la
 * rejette dans « À valider » ; le CONTRÔLEUR DES PAIEMENTS suit ensuite le remboursement. Le GESTIONNAIRE DES SITES ET
 * BOUTIQUES voit les statuts, sans aucun montant.
 */
class CautionsRembourserController extends Controller
{
    public function __construct(private readonly AvancesBoutiques $avances) {}

    /** @return Builder<Remboursement> annulations faute d'avance en attente de retenue */
    public static function requete(): Builder
    {
        return Remboursement::query()->where('origine', Remboursement::ANNULATION_AVANCE)->where('statut', Remboursement::EN_ATTENTE_RETENUE);
    }

    public function index(Request $request): Response|StreamedResponse|View
    {
        Gate::authorize(Droit::AvancesVoir->value);
        $a = __('admin.avances');
        $c = $a['colonnes'];

        return Tableau::pour(self::requete()->with(['reservation.boutique', 'user', 'decisions']), 'cautions')
            ->colonnes([
                Colonne::make('reference', $c['reference'])->recherchable(fn ($q, $t) => $q->whereHas('reservation', fn ($r) => $r->whereContient('reference', $t)))
                    ->valeur(fn (Remboursement $rb) => $rb->reservation?->reference)->classe('font-mono font-semibold whitespace-nowrap'),
                Colonne::make('boutique', $c['boutique'])->valeur(fn (Remboursement $rb) => $rb->reservation?->boutique?->code)->classe('font-mono whitespace-nowrap'),
                Colonne::make('citoyen', $c['citoyen'])->droit(Droit::BoutiquesVoir)->valeur(fn (Remboursement $rb) => $rb->user?->name),
                Colonne::make('annulee', $c['annulee'])->triable('created_at')->classe('whitespace-nowrap')
                    ->valeur(fn (Remboursement $rb) => $rb->created_at->format('d/m/Y H:i')),
                Colonne::make('caution', $c['caution'])->droit(Droit::BoutiquesVoir)->numerique()->triable('montant')
                    ->valeur(fn (Remboursement $rb) => format_fcfa((int) $rb->reservation?->caution))->texte(fn (Remboursement $rb) => $rb->reservation?->caution),
                Colonne::make('statut', $c['statut'])->valeur(fn (Remboursement $rb) => $rb->libelleStatut()),
                Colonne::make('retenue', $c['retenue'])->vue('admin.cautions._retenue', 'remboursement')
                    ->texte(fn (Remboursement $rb) => ($d = $rb->decisions->sortByDesc('id')->first()) ? $d->resume().' · '.__('admin.avances.statuts.'.$d->statut) : $a['aucune_decision']),
            ])
            ->triParDefaut('annulee')
            ->unite($a['unite'])
            ->repondre($request, fn (ResultatTableau $t) => view('admin.cautions.index', ['tableau' => $t]));
    }

    /** Retenue proposée par le RÉGULATEUR (motif obligatoire). */
    public function proposer(Request $request, Remboursement $remboursement): RedirectResponse
    {
        Gate::authorize(Droit::AvancesProposer->value);
        $donnees = $request->validate([
            'retenue_pct' => ['required', 'integer', 'min:0', 'max:100'],
            'motif' => ['required', 'string', 'min:5', 'max:1000'],
        ], [], __('admin.avances.champs'));
        $this->avances->proposerRetenue($remboursement, (int) $donnees['retenue_pct'], $donnees['motif'], $request->user());

        return redirect()->route('admin.cautions')->with('succes', __('admin.avances.messages.proposee'));
    }

    public function confirmer(Request $request, DecisionAvance $decision): RedirectResponse
    {
        Gate::authorize(Droit::AvancesDecider->value);
        $this->avances->confirmer($decision, $request->user());

        return redirect()->route('admin.a-valider')->with('succes', __('admin.avances.messages.confirmee'));
    }

    public function rejeter(Request $request, DecisionAvance $decision): RedirectResponse
    {
        Gate::authorize(Droit::AvancesDecider->value);
        $motif = $request->validate(['motif' => ['required', 'string', 'min:5', 'max:1000']], [], ['motif' => __('admin.avances.champs.motif')])['motif'];
        $this->avances->rejeter($decision, $request->user(), $motif);

        return redirect()->route('admin.a-valider')->with('succes', __('admin.avances.messages.rejetee'));
    }
}
