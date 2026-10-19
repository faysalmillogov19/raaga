<?php

namespace App\Http\Controllers\Admin;

use App\Enums\Droit;
use App\Enums\StatutCampagneBoutiques;
use App\Enums\StatutReservationBoutique;
use App\Http\Controllers\Admin\Concerns\EnregistreEtSoumet;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\CampagneBoutiquesRequest;
use App\Models\CampagneBoutiques;
use App\Models\MoyenPaiement;
use App\Models\ParametrageDecision;
use App\Models\ReservationBoutique;
use App\Models\Site;
use App\Models\TypeBoutique;
use App\Services\Audit\Journal;
use App\Services\Backoffice\ConfirmationRenforcee;
use App\Services\Backoffice\RegleGestionException;
use App\Services\Boutiques\EcheancesCampagnes;
use App\Services\Boutiques\ReservationsBoutiques;
use App\Services\Parametrage\WorkflowParametrage;
use App\Support\BadgeStatut;
use App\Support\MarkdownLeger;
use App\Support\Tableau\Colonne;
use App\Support\Tableau\Filtre;
use App\Support\Tableau\ResultatTableau;
use App\Support\Tableau\Tableau;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\HtmlString;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Campagnes de réservation des boutiques, au premier arrivé : le RÉGULATEUR saisit et soumet (période, sites, nombre
 * maximum par personne, délai de paiement de la caution, moyens de paiement), le VALIDATEUR confirme ou rejette ; la
 * campagne confirmée s'ouvre et se clôture seule à ses dates (EcheancesCampagnes), le VALIDATEUR peut la clôturer plus tôt ;
 * il attribue ensuite les boutiques réservées (avance payée, contrat signé). Montants : grille tarifaire.
 */
class CampagnesBoutiquesController extends Controller
{
    use EnregistreEtSoumet;

    public function __construct(
        private readonly WorkflowParametrage $workflow,
        private readonly Journal $journal,
        private readonly ConfirmationRenforcee $confirmation,
        private readonly ReservationsBoutiques $reservations,
        private readonly EcheancesCampagnes $echeances,
    ) {}

    /** Campagnes, les plus récentes d'abord ; le RÉGULATEUR coche les brouillons et rejetées pour les soumettre ensemble. */
    public function index(Request $request): Response|StreamedResponse|View
    {
        Gate::authorize(Droit::BoutiquesVoir->value);
        $this->echeances->appliquer();
        $c = __('admin.boutiques.colonnes');

        return Tableau::pour(CampagneBoutiques::query()->withCount(['reservations' => fn ($q) => $q->whereIn('statut', StatutReservationBoutique::actifs())]), 'campagnes')
            ->colonnes([
                Colonne::make('nom', $c['nom'])->triable()->recherchable()
                    ->valeur(fn (CampagneBoutiques $x) => new HtmlString('<a href="'.e(route('admin.boutiques.show', $x)).'" class="font-bold text-vert-fonce underline underline-offset-2">'.e($x->nom).'</a>'))
                    ->texte(fn (CampagneBoutiques $x) => $x->nom),
                Colonne::make('statut', $c['statut'])->triable()->valeur(fn (CampagneBoutiques $x) => BadgeStatut::html($x->statut))->texte(fn (CampagneBoutiques $x) => $x->statut->label()),
                Colonne::make('periode', $c['periode'])->triable('date_ouverture')->classe('whitespace-nowrap')
                    ->valeur(fn (CampagneBoutiques $x) => $x->date_ouverture?->format('d/m/Y H:i').' → '.$x->date_cloture?->format('d/m/Y H:i')),
                Colonne::make('boutiques', $c['reservations'])->numerique()->valeur(fn (CampagneBoutiques $x) => format_nombre((int) $x->reservations_count)),
                Colonne::make('version', $c['version'])->numerique()->valeur(fn (CampagneBoutiques $x) => $x->version ?: ''),
            ])
            ->selection('campagne', $request->user())
            ->departage(fn ($q) => $q->orderByDesc('id'))
            ->unite(__('admin.boutiques.unite'))
            ->repondre($request, fn (ResultatTableau $t) => view('admin.boutiques.index', ['tableau' => $t]));
    }

    /** Aperçu de la présentation (Markdown léger) : le même rendu que la page publique, tout HTML saisi neutralisé. */
    public function apercu(Request $request): JsonResponse
    {
        Gate::authorize(Droit::BoutiquesSaisir->value);
        $texte = (string) $request->validate(['texte' => ['nullable', 'string', 'max:2000']])['texte'];

        return response()->json(['html' => (string) MarkdownLeger::html($texte)]);
    }

    public function create(): View
    {
        Gate::authorize(Droit::BoutiquesSaisir->value);

        return view('admin.boutiques.formulaire', [
            'campagne' => new CampagneBoutiques([
                'date_ouverture' => now()->addMonth()->startOfDay()->setTime(8, 0),
                'date_cloture' => now()->addMonths(2)->setTime(23, 59),
                'canaux_actifs' => MoyenPaiement::proposables()->pluck('code')->all(),
                'sites' => [],
                'types' => [],
            ]),
            'sitesDisponibles' => $this->sitesDisponibles(),
            'typesDisponibles' => TypeBoutique::query()->proposables()->orderBy('ordre')->orderBy('libelle')->get(),
            'mode' => 'creation',
        ]);
    }

    public function store(CampagneBoutiquesRequest $request): RedirectResponse
    {
        Gate::authorize(Droit::BoutiquesSaisir->value);

        $campagne = DB::transaction(function () use ($request) {
            $campagne = CampagneBoutiques::create([...$request->parametres(), 'statut' => StatutCampagneBoutiques::Brouillon, 'cree_par' => $request->user()->id]);
            $this->journal->ecrire($request->user(), 'campagne_boutiques.creee', 'CampagneBoutiques', $campagne->id, null, ['nom' => $campagne->nom]);

            return $campagne;
        });

        return $this->puisSoumettre($request, redirect()->route('admin.boutiques.show', $campagne)->avecSucces('admin.boutiques.messages.enregistree'),
            'campagne', $campagne, route('admin.boutiques.edit', $campagne));
    }

    public function show(Request $request, CampagneBoutiques $campagne): Response|StreamedResponse|View
    {
        Gate::authorize(Droit::BoutiquesVoir->value);
        $this->echeances->appliquer();
        $campagne->refresh();

        $versions = $campagne->versions()->with('auteur')->get();
        $soumise = $versions->last();
        $precedente = $versions->count() > 1 ? $versions->get($versions->count() - 2) : null;
        $c = __('admin.boutiques.reservations.colonnes');

        return Tableau::pour(ReservationBoutique::query()->where('campagne_boutiques_id', $campagne->id)->with(['boutique', 'user']), 'reservations')
            ->colonnes([
                Colonne::make('reference', $c['reference'])->triable()->recherchable()->classe('font-mono font-semibold'),
                Colonne::make('boutique', $c['boutique'])->recherchable(fn ($q, $t) => $q->whereHas('boutique', fn ($b) => $b->whereContient('code', $t)))
                    ->valeur(fn (ReservationBoutique $r) => $r->boutique?->code)->classe('font-mono'),
                Colonne::make('citoyen', $c['citoyen'])->recherchable(fn ($q, $t) => $q->whereHas('user', fn ($u) => $u->whereContient('name', $t)))
                    ->valeur(fn (ReservationBoutique $r) => $r->user?->name),
                Colonne::make('statut', $c['statut'])->triable()->valeur(fn (ReservationBoutique $r) => $r->libelleStatut()),
                Colonne::make('avance', $c['avance'])->valeur(fn (ReservationBoutique $r) => match (true) {
                    $r->avance_payee_le !== null => __('admin.boutiques.reservations.avance_payee', ['date' => $r->avance_payee_le->format('d/m/Y')]),
                    $r->avance_due_le !== null && $r->statut === StatutReservationBoutique::Reservee => __('admin.boutiques.reservations.avance_due', ['date' => $r->avance_due_le->format('d/m/Y')]),
                    default => '',
                }),
                Colonne::make('caution', $c['caution'])->numerique()->triable()->valeur(fn (ReservationBoutique $r) => format_fcfa($r->caution))->texte(fn (ReservationBoutique $r) => $r->caution),
                Colonne::make('date', $c['date'])->triable('created_at')->classe('whitespace-nowrap')->valeur(fn (ReservationBoutique $r) => $r->created_at->format('d/m/Y H:i')),
                Colonne::make('actions', $c['actions'])->vue('admin.boutiques._reservation-actions', 'reservation')->exportable(false),
            ])
            ->filtres([Filtre::make('statut', $c['statut'], collect(StatutReservationBoutique::actifs())->merge([StatutReservationBoutique::Expiree, StatutReservationBoutique::Annulee])
                ->mapWithKeys(fn ($s) => [$s->value => $s->label()])->all(), 'statut')])
            ->triParDefaut('date', 'desc')
            ->unite(__('admin.boutiques.reservations.unite'))
            ->export('Raaga_reservations-boutiques-'.$campagne->id)
            ->repondre($request, fn (ResultatTableau $t) => view('admin.boutiques.show', [
                'campagne' => $campagne,
                'tableau' => $t,
                'compteurs' => $campagne->compteurs(),
                'versions' => $versions->reverse()->values(),
                'decisions' => ParametrageDecision::query()->where('objet_type', $campagne->typeParametrage())->where('objet_id', $campagne->id)
                    ->with('decideur')->orderByDesc('id')->get(),
                'differentiel' => $campagne->estSoumis() && $soumise ? WorkflowParametrage::differentiel($precedente?->parametres ?? [], $soumise->parametres) : [],
                'premiereVersion' => $precedente === null,
                'totpExige' => ConfirmationRenforcee::totpExige($request->user()),
                'sitesSansGrille' => EcheancesCampagnes::sitesSansGrille($campagne),
                // Ouverture automatique empêchée (grille, paiements, autre campagne ouverte) : affichée sur la fiche.
                'blocageOuverture' => $campagne->statut === StatutCampagneBoutiques::Confirmee ? $this->echeances->blocage($campagne) : null,
                // Ancien tirage au sort : conservé en lecture seule (tables en ajout seul).
                'historiqueTirages' => DB::table('tirages_boutiques')->where('campagne_boutiques_id', $campagne->id)->orderBy('id')->get(),
            ]));
    }

    public function edit(CampagneBoutiques $campagne): View|RedirectResponse
    {
        Gate::authorize(Droit::BoutiquesSaisir->value);

        if (! $campagne->estModifiable()) {
            return redirect()->route('admin.boutiques.show', $campagne)->with('erreur', __('admin.parametrage.regles.non_modifiable'));
        }

        return view('admin.boutiques.formulaire', ['campagne' => $campagne, 'sitesDisponibles' => $this->sitesDisponibles(),
            'typesDisponibles' => TypeBoutique::query()->proposables()->orderBy('ordre')->orderBy('libelle')->get(), 'mode' => 'modification']);
    }

    public function update(CampagneBoutiquesRequest $request, CampagneBoutiques $campagne): RedirectResponse
    {
        Gate::authorize(Droit::BoutiquesSaisir->value);

        if (! $campagne->estModifiable()) {
            return redirect()->route('admin.boutiques.show', $campagne)->with('erreur', __('admin.parametrage.regles.non_modifiable'));
        }

        $campagne->fill($request->parametres())->save();
        $this->journal->ecrire($request->user(), 'campagne_boutiques.modifiee', 'CampagneBoutiques', $campagne->id);

        return $this->puisSoumettre($request, redirect()->route('admin.boutiques.show', $campagne)->avecSucces('admin.boutiques.messages.enregistree'),
            'campagne', $campagne, route('admin.boutiques.edit', $campagne));
    }

    public function soumettre(Request $request, CampagneBoutiques $campagne): RedirectResponse
    {
        Gate::authorize(Droit::BoutiquesSaisir->value);

        return $this->executer($campagne, function () use ($request, $campagne) {
            // Aucun moyen de paiement de la caution ouvert : rien à proposer.
            $this->exigerPaiementsOuverts($campagne);
            $this->workflow->soumettre($campagne, $request->user());
        }, 'admin.parametrage.messages.soumis');
    }

    public function confirmer(Request $request, CampagneBoutiques $campagne): RedirectResponse
    {
        Gate::authorize(Droit::BoutiquesConfirmer->value);

        return $this->executer($campagne, function () use ($request, $campagne) {
            $this->workflow->confirmer($campagne, $request->user());
            // Date d'ouverture déjà atteinte : la campagne s'ouvre aussitôt.
            $this->echeances->appliquer();
        }, 'admin.parametrage.messages.confirme');
    }

    public function rejeter(Request $request, CampagneBoutiques $campagne): RedirectResponse
    {
        Gate::authorize(Droit::BoutiquesConfirmer->value);
        $motif = $request->validate(['motif' => ['required', 'string', 'min:5', 'max:1000']], ['motif.*' => __('admin.utilisateurs.erreurs.motif')])['motif'];

        return $this->executer($campagne, fn () => $this->workflow->rejeter($campagne, $request->user(), $motif), 'admin.parametrage.messages.rejete');
    }

    public function cloturer(Request $request, CampagneBoutiques $campagne): RedirectResponse
    {
        Gate::authorize(Droit::BoutiquesPiloter->value);

        return $this->executer($campagne, function () use ($request, $campagne) {
            $this->confirmation->verifier($request, $request->user());

            if ($campagne->statut !== StatutCampagneBoutiques::Ouverte) {
                throw new RegleGestionException(__('admin.boutiques.regles.cloture_ouverte'));
            }

            $campagne->forceFill(['statut' => StatutCampagneBoutiques::Cloturee, 'cloturee_le' => now()])->save();
            $this->journal->ecrire($request->user(), 'campagne_boutiques.cloturee', 'CampagneBoutiques', $campagne->id, ['statut' => 'OUVERTE'], ['statut' => 'CLOTUREE']);
        }, 'admin.boutiques.messages.cloturee');
    }

    /** Avance payée et contrat signé : boutique attribuée (VALIDATEUR). */
    /** Attributions à faire : réservations prêtes (caution et avance payées), toutes campagnes, les plus anciennes d'abord. */
    public function attributions(): View
    {
        Gate::authorize(Droit::BoutiquesPiloter->value);

        return view('admin.boutiques.attributions', [
            'reservations' => ReservationsBoutiques::aAttribuer()->with(['boutique', 'campagne'])->orderBy('avance_payee_le')->orderBy('id')->get(),
        ]);
    }

    public function attribuer(Request $request, CampagneBoutiques $campagne, ReservationBoutique $reservation): RedirectResponse
    {
        Gate::authorize(Droit::BoutiquesPiloter->value);
        abort_unless($reservation->campagne_boutiques_id === $campagne->id, 404);
        $donnees = $request->validate([
            'contrat_signe_le' => ['required', 'date', 'before_or_equal:today'],
        ], [], __('admin.boutiques.reservations.champs'));
        $this->reservations->attribuer($reservation, $donnees['contrat_signe_le'], $request->user());

        // Retour sur « Attributions à faire » quand l'attribution y a été faite.
        $retour = url()->previous() === route('admin.boutiques.attributions') ? route('admin.boutiques.attributions') : route('admin.boutiques.show', $campagne);

        return redirect($retour)->avecSucces('admin.boutiques.messages.attribuee');
    }

    /** Sites confirmés qui ont des boutiques réservables. */
    private function sitesDisponibles()
    {
        return Site::query()->confirmes()->whereHas('boutiques', fn ($q) => $q->whereHas('type', fn ($t) => $t->utilisables()->where('attribuable', true)))
            ->orderBy('nom')->get();
    }

    /** Sites de la campagne sans grille tarifaire confirmée : aucune boutique n'y serait réservable. @return list<string> */
    private function executer(CampagneBoutiques $campagne, Closure $action, string $message): RedirectResponse
    {
        try {
            $action();
        } catch (RegleGestionException $e) {
            return redirect()->route('admin.boutiques.show', $campagne)->with('erreur', $e->getMessage());
        }

        return redirect()->route('admin.boutiques.show', $campagne)->avecSucces($message);
    }

    /** Au moins un moyen de paiement de la caution réellement ouvert (compte de location renseigné ; opérateur réel pour un moyen par opérateur). @throws RegleGestionException */
    private function exigerPaiementsOuverts(CampagneBoutiques $campagne): void
    {
        if ($raison = $campagne->raisonPaiementsFermes()) {
            throw new RegleGestionException(__('admin.boutiques.regles.paiements_fermes', ['raison' => $raison]));
        }
    }
}
