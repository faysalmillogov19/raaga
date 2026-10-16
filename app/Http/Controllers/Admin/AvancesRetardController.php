<?php

namespace App\Http\Controllers\Admin;

use App\Enums\Droit;
use App\Enums\StatutPaiement;
use App\Enums\StatutReservationBoutique;
use App\Http\Controllers\Controller;
use App\Models\CampagneBoutiques;
use App\Models\DecisionAvance;
use App\Models\ReservationBoutique;
use App\Services\Boutiques\AvancesBoutiques;
use App\Support\Tableau\Colonne;
use App\Support\Tableau\Filtre;
use App\Support\Tableau\ResultatTableau;
use App\Support\Tableau\Tableau;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\HtmlString;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Avances en retard, au cas par cas : dossiers dont la date limite de l'avance est dépassée sans avance confirmée
 * (boutique toujours réservée). Le RÉGULATEUR propose une décision motivée ; le VALIDATEUR confirme ou rejette
 * prolonger et annuler dans « À valider ». Le GESTIONNAIRE DES SITES ET BOUTIQUES voit les statuts, sans aucun montant.
 */
class AvancesRetardController extends Controller
{
    public function __construct(private readonly AvancesBoutiques $avances) {}

    /** Dossiers « avance en retard » : retard marqué, avance toujours attendue. */
    public static function requete()
    {
        return ReservationBoutique::query()->where('statut', StatutReservationBoutique::Reservee)->whereNull('avance_payee_le')->whereNotNull('avance_retard_le');
    }

    public function index(Request $request): Response|StreamedResponse|View
    {
        Gate::authorize(Droit::AvancesVoir->value);
        $a = __('admin.avances');
        $c = $a['colonnes'];

        return Tableau::pour(self::requete()->with(['boutique', 'user', 'campagne', 'paiements', 'decisionsAvance']), 'avances')
            ->colonnes([
                Colonne::make('reference', $c['reference'])->triable()->recherchable()->classe('font-mono font-semibold whitespace-nowrap'),
                Colonne::make('boutique', $c['boutique'])->recherchable(fn ($q, $t) => $q->whereHas('boutique', fn ($b) => $b->whereContient('code', $t)))
                    ->valeur(fn (ReservationBoutique $r) => $r->boutique?->code)->classe('font-mono whitespace-nowrap'),
                Colonne::make('citoyen', $c['citoyen'])->droit(Droit::BoutiquesVoir)->valeur(fn (ReservationBoutique $r) => $r->user?->name),
                // Date limite et retard sur une ligne ; montants (avance, caution) seulement pour les profils des campagnes.
                Colonne::make('date_limite', $c['date_limite'])->triable('avance_due_le')
                    ->valeur(fn (ReservationBoutique $r) => new HtmlString(e($r->avance_due_le?->format('d/m/Y H:i')).'<span class="texte-carte block">'
                        .e(trans_choice('admin.avances.jours', $j = max(1, (int) $r->avance_due_le->diffInDays(now())), ['n' => $j])).'</span>'))
                    ->texte(fn (ReservationBoutique $r) => $r->avance_due_le?->format('d/m/Y H:i')),
                Colonne::make('montants', $c['montants'])->droit(Droit::BoutiquesVoir)->classe('whitespace-nowrap')
                    ->valeur(fn (ReservationBoutique $r) => new HtmlString(e(__('admin.avances.montant_avance', ['montant' => format_fcfa((int) $r->avance)]))
                        .'<span class="texte-carte block">'.e(__('admin.avances.montant_caution', ['montant' => format_fcfa((int) $r->caution)])).'</span>'))
                    ->texte(fn (ReservationBoutique $r) => $r->avance),
                Colonne::make('paiement', $c['paiement'])->valeur(fn (ReservationBoutique $r) => self::etatPaiement($r)),
                // Dernière décision et proposition (RÉGULATEUR) dans la même colonne.
                Colonne::make('decision', $c['decision'])->vue('admin.avances._actions', 'reservation')
                    ->texte(fn (ReservationBoutique $r) => ($d = $r->decisionsAvance->first()) ? $d->resume().' · '.__('admin.avances.statuts.'.$d->statut) : $a['aucune_decision']),
            ])
            ->filtres([Filtre::make('campagne', $c['campagne'], CampagneBoutiques::query()->orderByDesc('id')->pluck('nom', 'id')->all(), 'campagne_boutiques_id')])
            ->triParDefaut('date_limite')
            ->unite($a['unite'])
            ->repondre($request, fn (ResultatTableau $t) => view('admin.avances.index', ['tableau' => $t]));
    }

    /** Paiement de l'avance en cours : preuve en contrôle, incomplet, en attente, ou aucun. */
    private static function etatPaiement(ReservationBoutique $r): string
    {
        $p = $r->paiements->where('nature', 'AVANCE')->sortByDesc('id')->first(fn ($p) => $p->statut === StatutPaiement::EnAttente);

        return __('admin.avances.paiements.'.match (true) {
            $p === null => 'aucun',
            $p->enControle() => 'controle',
            $p->aCompleter() => 'incomplet',
            default => 'attente',
        });
    }

    /** Proposition du RÉGULATEUR (motif obligatoire) : prolonger, annuler avec retenue, ou attendre. */
    public function proposer(Request $request, ReservationBoutique $reservation): RedirectResponse
    {
        Gate::authorize(Droit::AvancesProposer->value);
        $donnees = $request->validate([
            'decision' => ['required', Rule::in(DecisionAvance::DECISIONS)],
            'motif' => ['required', 'string', 'min:5', 'max:1000'],
            'nouvelle_date' => ['nullable', 'required_if:decision,'.DecisionAvance::PROLONGER, 'date', 'after:now'],
            'retenue_pct' => ['nullable', 'required_if:decision,'.DecisionAvance::ANNULER, 'integer', 'min:0', 'max:100'],
        ], [], __('admin.avances.champs'));
        $this->avances->proposer($reservation, $donnees['decision'], $donnees['motif'],
            isset($donnees['nouvelle_date']) ? Carbon::parse($donnees['nouvelle_date'])->endOfDay() : null,
            isset($donnees['retenue_pct']) ? (int) $donnees['retenue_pct'] : null, $request->user());

        return redirect()->route('admin.avances')->with('succes', __($donnees['decision'] === DecisionAvance::ATTENDRE
            ? 'admin.avances.messages.notee' : 'admin.avances.messages.proposee'));
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
