<?php

namespace App\Http\Controllers\Compte;

use App\Enums\DocumentAccepte;
use App\Enums\Role;
use App\Enums\StatutCampagneBoutiques;
use App\Enums\StatutReservationBoutique;
use App\Enums\TypeDocumentKyc;
use App\Http\Controllers\Controller;
use App\Models\Boutique;
use App\Models\CampagneBoutiques;
use App\Models\ReservationBoutique;
use App\Models\Site;
use App\Models\User;
use App\Services\Boutiques\AvancesBoutiques;
use App\Services\Boutiques\ReservationsBoutiques;
use App\Support\ThemePublic;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Boutiques du marché, au premier arrivé : plan public (site, niveau, bloc ; statuts en couleurs, aucune donnée
 * personnelle), récapitulatif d'une boutique et pré-réservation (citoyen connecté, téléphone vérifié, pièce jointe),
 * suivi de la réservation.
 */
class BoutiquesController extends Controller
{
    public function __construct(private readonly ReservationsBoutiques $reservations) {}

    /** Campagne affichée : ouverte, sinon la prochaine confirmée, sinon la dernière clôturée. */
    private function campagne(): ?CampagneBoutiques
    {
        return CampagneBoutiques::ouverte()
            ?? CampagneBoutiques::query()->where('statut', StatutCampagneBoutiques::Confirmee)->orderBy('date_ouverture')->first()
            ?? CampagneBoutiques::query()->where('statut', StatutCampagneBoutiques::Cloturee)->latest('cloturee_le')->first();
    }

    /** Plan public : choix du site, du niveau et du bloc, puis plan schématique du bloc. */
    public function index(Request $request): View
    {
        $campagne = $this->campagne();
        $sites = $campagne?->sitesConcernes() ?? collect();
        $site = $sites->firstWhere('id', (int) $request->query('site')) ?? $sites->first();
        // Filtre par type : types de la campagne présents sur le site.
        $types = $campagne && $site ? ReservationsBoutiques::typesDuSite($campagne, $site->id) : collect();
        $type = $types->firstWhere('id', (int) $request->query('type'));
        $niveaux = $campagne && $site ? ReservationsBoutiques::niveauxEtBlocs($campagne, $site->id, $type?->id) : collect();
        $niveau = $niveaux->has((string) $request->query('niveau')) ? (string) $request->query('niveau') : $niveaux->keys()->first();
        $blocs = $niveau !== null ? $niveaux[$niveau] : [];
        $bloc = in_array($request->query('bloc'), $blocs, true) ? (string) $request->query('bloc') : ($blocs[0] ?? null);
        $user = $request->user();

        // Thème « nuit » : même données, présentation des maquettes (public.boutiques.index-nuit).
        return view(ThemePublic::nuit() ? 'public.boutiques.index-nuit' : 'public.boutiques.index', [
            'campagne' => $campagne,
            'ouverte' => $campagne?->accepteReservations() ?? false,
            'sites' => $sites, 'site' => $site, 'niveaux' => $niveaux, 'niveau' => $niveau, 'blocs' => $blocs, 'bloc' => $bloc,
            'types' => $types, 'type' => $type,
            // Limites par type : ce qui reste au citoyen connecté (null pour un visiteur : les limites seules).
            'limites' => $campagne ? self::limites($campagne, $user) : null,
            'plan' => $campagne && $site && $niveau !== null && $bloc !== null ? ReservationsBoutiques::plan($campagne, $site->id, $niveau, $bloc, $type?->id) : collect(),
            'mesReservations' => $campagne && $user ? ReservationBoutique::query()->where('campagne_boutiques_id', $campagne->id)->where('user_id', $user->id)
                ->whereIn('statut', StatutReservationBoutique::actifs())->with('boutique')->get() : collect(),
        ]);
    }

    /**
     * Limites par type de la campagne, et ce qui reste au citoyen connecté : « Vous pouvez encore réserver : Boutique (1), Magasin (1) ».
     *
     * @return array{resume: string, restants: array<int, int>|null}
     */
    private static function limites(CampagneBoutiques $campagne, ?User $user): array
    {
        $types = $campagne->typesConcernes();
        $restants = $user?->hasRole(Role::Citoyen) ? ReservationsBoutiques::restants($campagne, $user) : null;
        // En clair : le nom du type, puis le nombre (« Boutique (1), Magasin (1) »), jamais le seul code.
        $liste = fn (callable $n) => $types->map(fn ($t) => ($t->libelle ?: $t->code).' ('.$n($t).')')->implode(', ');

        return [
            'restants' => $restants,
            'resume' => $restants === null
                ? __('public.boutiques.limites.par_personne', ['liste' => $liste(fn ($t) => $campagne->limitePour($t->id))])
                : (array_sum($restants) === 0 ? __('public.boutiques.limites.aucun_restant')
                    : __('public.boutiques.limites.restants', ['liste' => $types->filter(fn ($t) => ($restants[$t->id] ?? 0) > 0)
                        ->map(fn ($t) => ($t->libelle ?: $t->code).' ('.$restants[$t->id].')')->implode(', ')])),
        ];
    }

    public function reglement(): View
    {
        $campagne = $this->campagne();
        abort_unless($campagne, 404);

        return view('public.boutiques.reglement', ['campagne' => $campagne]);
    }

    /** Récapitulatif d'une boutique (code, type, surface, loyer, caution, avance) et formulaire de réservation. */
    public function boutique(Request $request, string $code): View
    {
        $campagne = CampagneBoutiques::ouverte();
        $boutique = Boutique::query()->where('code', $code)->with(['type', 'site', 'niveauRef'])->firstOrFail();
        abort_unless($campagne && in_array($boutique->site_id, (array) $campagne->sites) && in_array($boutique->type_boutique_id, (array) $campagne->types), 404);

        return view('public.boutiques.boutique', [
            'campagne' => $campagne,
            'boutique' => $boutique,
            'tarif' => ReservationsBoutiques::tarifer($boutique),
            'detail' => ReservationsBoutiques::detail($boutique),
            'limites' => self::limites($campagne, $request->user()),
            'ouverte' => $campagne->accepteReservations(),
            'engagements' => ReservationsBoutiques::ENGAGEMENTS,
            'pieces' => ReservationsBoutiques::piecesPour($request->user()),
        ]);
    }

    public function reserver(Request $request, string $code): RedirectResponse
    {
        $campagne = CampagneBoutiques::ouverte();
        $boutique = Boutique::query()->where('code', $code)->firstOrFail();
        abort_unless($campagne, 404);

        $document = ['file', 'mimes:jpg,jpeg,png,pdf', 'mimetypes:image/jpeg,image/png,application/pdf', 'max:5120'];
        $donnees = $request->validate([
            'type_piece' => ['required', Rule::in(array_map(fn (TypeDocumentKyc $t) => $t->value, ReservationsBoutiques::piecesPour($request->user())))],
            'numero_piece' => ['required', 'string', 'min:5', 'max:40'],
            'piece' => ['required', ...$document],
            'rccm' => ['nullable', ...$document],
            'engagements' => ['array'],
            'engagements.*' => [Rule::enum(DocumentAccepte::class)],
        ], [], __('public.boutiques.reservation.attributs'));

        $reservation = $this->reservations->reserver(
            $campagne, $request->user(), $boutique, TypeDocumentKyc::from($donnees['type_piece']), $donnees['numero_piece'],
            $request->file('piece'), $request->file('rccm'), $donnees['engagements'] ?? [], (string) $request->ip(), (string) $request->userAgent(),
        );

        return redirect()->route('boutiques.reservation.show', $reservation->reference)->with('reservation.creee', true);
    }

    /** Suivi d'une réservation : uniquement par son titulaire. */
    public function suivi(Request $request, string $reference): View
    {
        $reservation = ReservationBoutique::query()->where('reference', $reference)->with(['campagne', 'boutique.type'])->firstOrFail();
        abort_unless($reservation->user_id === $request->user()->id && $request->user()->hasRole(Role::Citoyen), 403);
        // Avance non payée à la date limite (sans preuve en contrôle) : annulation, comme au passage de la tâche planifiée.
        app(AvancesBoutiques::class)->annulerSiEchue($reservation);

        return view('public.boutiques.reservation', ['reservation' => $reservation->refresh()]);
    }
}
