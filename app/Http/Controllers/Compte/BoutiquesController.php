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
use App\Models\InscriptionBoutique;
use App\Models\ReservationBoutique;
use App\Models\Site;
use App\Models\User;
use App\Services\Boutiques\AvancesBoutiques;
use App\Services\Boutiques\ListesAttente;
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
    public function __construct(private readonly ListesAttente $listes) {}

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

        $user = $request->user();
        $inscription = ListesAttente::inscriptionDe($boutique, $user);

        return view('public.boutiques.boutique', [
            'campagne' => $campagne,
            'boutique' => $boutique,
            // Liste d'attente : nombre de personnes déjà inscrites, inscription du citoyen, pièce déjà fournie.
            'interesses' => ListesAttente::interesses($boutique),
            'inscription' => $inscription,
            'pieceFournie' => ListesAttente::pieceFournie($campagne, $user),
            'tarif' => ReservationsBoutiques::tarifer($boutique),
            'detail' => ReservationsBoutiques::detail($boutique),
            'limites' => self::limites($campagne, $request->user()),
            'ouverte' => $campagne->accepteReservations(),
            'engagements' => ReservationsBoutiques::ENGAGEMENTS,
            'pieces' => ReservationsBoutiques::piecesPour($request->user()),
        ]);
    }

    /**
     * Inscription sur une boutique : libre, elle est pré-réservée au nom du citoyen (caution à payer) ; sinon, il prend
     * son rang sur la liste d'attente. La pièce d'identité n'est demandée qu'à la première inscription de la campagne.
     */
    public function reserver(Request $request, string $code): RedirectResponse
    {
        $campagne = CampagneBoutiques::ouverte();
        $boutique = Boutique::query()->where('code', $code)->firstOrFail();
        abort_unless($campagne, 404);
        $premiere = ListesAttente::pieceFournie($campagne, $request->user()) === null;

        $document = ['file', 'mimes:jpg,jpeg,png,pdf', 'mimetypes:image/jpeg,image/png,application/pdf', 'max:5120'];
        $donnees = $request->validate([
            'type_piece' => [Rule::requiredIf($premiere), 'nullable', Rule::in(array_map(fn (TypeDocumentKyc $t) => $t->value, ReservationsBoutiques::piecesPour($request->user())))],
            'numero_piece' => [Rule::requiredIf($premiere), 'nullable', 'string', 'min:5', 'max:40'],
            'piece' => [Rule::requiredIf($premiere), 'nullable', ...$document],
            'rccm' => ['nullable', ...$document],
            'engagements' => ['array'],
            'engagements.*' => [Rule::enum(DocumentAccepte::class)],
        ], [], __('public.boutiques.reservation.attributs'));

        $inscription = $this->listes->inscrire(
            $campagne, $request->user(), $boutique, isset($donnees['type_piece']) ? TypeDocumentKyc::from($donnees['type_piece']) : null, $donnees['numero_piece'] ?? null,
            $request->file('piece'), $request->file('rccm'), $donnees['engagements'] ?? [], (string) $request->ip(), (string) $request->userAgent(),
        );

        // Retenue : caution à payer ; sinon, rang sur la liste d'attente.
        return $inscription->reservation
            ? redirect()->route('boutiques.reservation.show', $inscription->reservation->reference)->with('reservation.creee', true)
            : redirect()->route('espace', ['onglet' => 'boutiques'])->avecSucces('public.boutiques.attente.inscrit', ['code' => $boutique->code, 'rang' => $inscription->rang()]);
    }

    /** Désistement : inscription en attente retirée, ou boutique dont le citoyen est attributaire libérée pour le suivant. */
    public function desister(Request $request, int $inscription): RedirectResponse
    {
        $i = InscriptionBoutique::query()->with('boutique')->findOrFail($inscription);
        abort_unless($i->user_id === $request->user()->id, 403);
        $this->listes->desister($i, $request->user());

        return redirect()->route('espace', ['onglet' => 'boutiques'])->avecSucces('public.boutiques.attente.desiste', ['code' => (string) $i->boutique?->code]);
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
