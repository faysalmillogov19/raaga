<?php

namespace App\Http\Controllers\Compte;

use App\Enums\DocumentAccepte;
use App\Enums\Role;
use App\Enums\StatutCampagneBoutiques;
use App\Enums\StatutReservationBoutique;
use App\Enums\TypeCarte;
use App\Enums\TypeDocumentKyc;
use App\Http\Controllers\Controller;
use App\Models\Boutique;
use App\Models\CampagneBoutiques;
use App\Models\InscriptionBoutique;
use App\Models\ReservationBoutique;
use App\Models\SecteurActivite;
use App\Models\Site;
use App\Models\User;
use App\Services\Boutiques\AvancesBoutiques;
use App\Services\Boutiques\ListesAttente;
use App\Services\Boutiques\RelancesInteret;
use App\Services\Boutiques\ReservationsBoutiques;
use App\Services\Cartes\Cartes;
use App\Services\Kyc\JustificatifsGroupeSanguin;
use App\Support\Ifu;
use App\Support\Nip;
use App\Support\Telephone;
use App\Support\ThemePublic;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Boutiques du marché, au premier arrivé : plan public (site, niveau, bloc ; statuts en couleurs, aucune donnée
 * personnelle), récapitulatif d'une boutique et pré-réservation (citoyen connecté, téléphone vérifié, pièce jointe),
 * suivi de la réservation.
 */
class BoutiquesController extends Controller
{
    /** Résultats de recherche affichés au plus (les moins chers d'abord). */
    public const RESULTATS_AFFICHES = 50;

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
        // Recherche (05/11/2026) : coût à l'entrée par zone, et boutiques du site filtrées par budget, zone, niveau, surface.
        $filtres = self::filtresRecherche($request);
        $plan = $campagne && $site && $niveau !== null && $bloc !== null ? ReservationsBoutiques::plan($campagne, $site->id, $niveau, $bloc, $type?->id) : collect();
        $resultats = $campagne && $site && $filtres !== [] ? ReservationsBoutiques::rechercher($campagne, $site->id, $filtres, $type?->id) : null;

        // Thème « nuit » : même données, présentation des maquettes (public.boutiques.index-nuit).
        return view(ThemePublic::nuit() ? 'public.boutiques.index-nuit' : 'public.boutiques.index', [
            'campagne' => $campagne,
            'ouverte' => $campagne?->accepteReservations() ?? false,
            // Relance en cours : boutiques de nouveau ouvertes à ce membre de la liste d'attente générale.
            'relance' => $campagne ? RelancesInteret::boutiquesOuvertesA($campagne, $user) : [],
            'sites' => $sites, 'site' => $site, 'niveaux' => $niveaux, 'niveau' => $niveau, 'blocs' => $blocs, 'bloc' => $bloc,
            'types' => $types, 'type' => $type,
            // Limites par type : ce qui reste au citoyen connecté (null pour un visiteur : les limites seules).
            'limites' => $campagne ? self::limites($campagne, $user) : null,
            'plan' => $plan,
            'coutsParZone' => $campagne && $site ? ReservationsBoutiques::coutsParZone($campagne, $site->id, $type?->id) : collect(),
            'filtres' => $filtres, 'resultats' => $resultats,
            // Nombre de personnes en liste d'attente par boutique (un nombre seulement, jamais de nom).
            'enAttente' => ListesAttente::enAttenteParBoutique($plan->pluck('id')->merge($resultats?->pluck('id') ?? [])->unique()->values()->all()),
            'mesReservations' => $campagne && $user ? ReservationBoutique::query()->where('campagne_boutiques_id', $campagne->id)->where('user_id', $user->id)
                ->whereIn('statut', StatutReservationBoutique::actifs())->with('boutique')->get() : collect(),
        ]);
    }

    /**
     * Filtres de la recherche lus dans l'adresse : montants en FCFA et surfaces en m² positifs, zone et niveau en texte
     * court ; un filtre vide ou invalide est ignoré. @return array<string, int|float|string>
     */
    private static function filtresRecherche(Request $request): array
    {
        $filtres = [];
        foreach (['budget_min', 'budget_max'] as $cle) {
            $v = preg_replace('/\D/', '', (string) $request->query($cle, ''));
            if ($v !== '' && strlen($v) <= 12) {
                $filtres[$cle] = (int) $v;
            }
        }
        foreach (['surface_min', 'surface_max'] as $cle) {
            $v = str_replace(',', '.', trim((string) $request->query($cle, '')));
            if (is_numeric($v) && (float) $v >= 0 && (float) $v <= 100000) {
                $filtres[$cle] = (float) $v;
            }
        }
        foreach (['zone', 'niveau'] as $cle) {
            $v = trim((string) $request->query($cle === 'niveau' ? 'niveau_recherche' : $cle, ''));
            if ($v !== '' && mb_strlen($v) <= 10) {
                $filtres[$cle] = $v;
            }
        }

        return $filtres;
    }

    /**
     * Limites par type de la campagne, et ce qui reste au citoyen connecté : « Vous pouvez encore réserver : Boutique (1), Magasin (1) ».
     *
     * Toujours suivi de la limite par commerçant (deux boutiques, toutes campagnes confondues).
     *
     * @return array{resume: string, restants: array<int, int>|null, commercant_atteint: bool}
     */
    private static function limites(CampagneBoutiques $campagne, ?User $user): array
    {
        $types = $campagne->typesConcernes();
        $restants = $user?->hasRole(Role::Citoyen) ? ReservationsBoutiques::restants($campagne, $user) : null;
        // En clair : le nom du type, puis le nombre (« Boutique (1), Magasin (1) »), jamais le seul code.
        $liste = fn (callable $n) => $types->map(fn ($t) => ($t->libelle ?: $t->code).' ('.$n($t).')')->implode(', ');

        return [
            'restants' => $restants,
            'commercant_atteint' => $restants !== null && ReservationsBoutiques::restantCommercant($user) === 0,
            'resume' => ($restants === null
                ? __('public.boutiques.limites.par_personne', ['liste' => $liste(fn ($t) => $campagne->limitePour($t->id))])
                : (array_sum($restants) === 0 ? __('public.boutiques.limites.aucun_restant')
                    : __('public.boutiques.limites.restants', ['liste' => $types->filter(fn ($t) => ($restants[$t->id] ?? 0) > 0)
                        ->map(fn ($t) => ($t->libelle ?: $t->code).' ('.$restants[$t->id].')')->implode(', ')])))
                .' '.__('public.boutiques.limites.par_commercant', ['n' => ReservationsBoutiques::LIMITE_PAR_COMMERCANT]),
        ];
    }

    public function reglement(): View
    {
        $campagne = $this->campagne();
        abort_unless($campagne, 404);

        return view('public.boutiques.reglement', ['campagne' => $campagne]);
    }

    /**
     * Campagne d'une boutique pour ce citoyen : la campagne ouverte, ou celle d'une relance en cours qui lui ouvre la
     * boutique (liste d'attente générale, même après la clôture des réservations).
     */
    private static function campagnePour(Boutique $boutique, ?User $user): ?CampagneBoutiques
    {
        return CampagneBoutiques::ouverte() ?? RelancesInteret::ouvertePour($boutique, $user)?->campagne;
    }

    /** Récapitulatif d'une boutique (code, type, surface, loyer, caution, avance) et formulaire de réservation. */
    public function boutique(Request $request, string $code): View
    {
        $boutique = Boutique::query()->where('code', $code)->with(['type', 'site', 'niveauRef'])->firstOrFail();
        $campagne = self::campagnePour($boutique, $request->user());
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
            // Pièce vérifiée du compte (reprise) ; sexe et lieu de naissance demandés seulement s'ils manquent.
            'pieceCompte' => $user ? ReservationsBoutiques::pieceDuCompte($user) : null,
            'sexeLieuDemandes' => $user && self::sexeLieuDemandes($campagne, $user),
            // Activité et personne à prévenir déjà renseignées : rappelées, modifiables sur demande.
            'infosCommercantConnues' => $user && self::infosCommercantConnues($user),
            'secteurLibelle' => $user ? SecteurActivite::libellePour($user) : null,
            'tarif' => ReservationsBoutiques::tarifer($boutique),
            'detail' => ReservationsBoutiques::detail($boutique),
            'limites' => self::limites($campagne, $request->user()),
            'ouverte' => $campagne->accepteReservations() || RelancesInteret::ouvertePour($boutique, $user) !== null,
            'engagements' => ReservationsBoutiques::ENGAGEMENTS,
            'pieces' => ReservationsBoutiques::piecesPour($request->user()),
            'secteurs' => SecteurActivite::parFamille(),
            'secteurAutre' => SecteurActivite::query()->where('code', SecteurActivite::AUTRE)->value('id'),
            // Première réservation d'une personne physique : numéro IFU et attestation.
            'attestationIfu' => $user && ReservationsBoutiques::attestationIfuAFournir($user),
            'groupes' => User::GROUPES_SANGUINS,
        ]);
    }

    /**
     * Sexe et lieu de naissance à saisir (carte de commerçant) : personne physique, à la première inscription de la
     * campagne ; avec une pièce vérifiée, seulement s'ils manquent au profil.
     */
    private static function sexeLieuDemandes(CampagneBoutiques $campagne, User $user): bool
    {
        if ($user->estPersonneMorale() || ListesAttente::pieceFournie($campagne, $user)) {
            return false;
        }

        return ReservationsBoutiques::pieceDuCompte($user) === null || blank($user->sexe) || blank($user->lieu_naissance);
    }

    /**
     * Activité (secteur encore proposé, précision donnée pour « Autre activité ») et personne à prévenir déjà
     * renseignées : le formulaire les rappelle au lieu de les redemander (05/11/2026).
     */
    private static function infosCommercantConnues(User $user): bool
    {
        $secteur = $user->secteur_activite_id ? SecteurActivite::query()->where('actif', true)->find($user->secteur_activite_id) : null;

        return $secteur !== null && ($secteur->code !== SecteurActivite::AUTRE || filled($user->secteur_activite_autre))
            && filled($user->personne_prevenir_nom) && filled($user->personne_prevenir_telephone);
    }

    /**
     * Inscription sur une boutique : libre, elle est pré-réservée au nom du citoyen (caution à payer) ; sinon, il prend
     * son rang sur la liste d'attente. La pièce d'identité n'est demandée qu'à la première inscription de la campagne,
     * et jamais si le compte a une pièce vérifiée.
     */
    public function reserver(Request $request, string $code): RedirectResponse
    {
        $boutique = Boutique::query()->where('code', $code)->firstOrFail();
        $campagne = self::campagnePour($boutique, $request->user());
        abort_unless($campagne, 404);
        $user = $request->user();
        $premiere = ListesAttente::pieceFournie($campagne, $user) === null && ReservationsBoutiques::pieceDuCompte($user) === null;
        $sexeLieu = self::sexeLieuDemandes($campagne, $user);
        $attestation = ReservationsBoutiques::attestationIfuAFournir($user);

        $document = ['file', 'mimes:jpg,jpeg,png,pdf', 'mimetypes:image/jpeg,image/png,application/pdf', 'max:5120'];
        $donnees = $request->validate([
            'type_piece' => [Rule::requiredIf($premiere), 'nullable', Rule::in(array_map(fn (TypeDocumentKyc $t) => $t->value, ReservationsBoutiques::piecesPour($request->user())))],
            // CNIB ou CIB-AES : le numéro est le NIP, 17 chiffres (06/11/2026).
            'numero_piece' => [Rule::requiredIf($premiere), 'nullable', 'string', 'min:5', 'max:40', Nip::regle(TypeDocumentKyc::tryFrom((string) $request->input('type_piece')))],
            'piece' => [Rule::requiredIf($premiere), 'nullable', ...$document],
            // Sexe et lieu de naissance, lus sur la pièce d'identité d'une personne physique (carte de commerçant).
            'sexe' => [Rule::requiredIf($sexeLieu), 'nullable', Rule::in(User::SEXES)],
            'lieu_naissance' => [Rule::requiredIf($sexeLieu), 'nullable', 'string', 'min:2', 'max:100'],
            'engagements' => ['array'],
            'engagements.*' => [Rule::enum(DocumentAccepte::class)],
            // Activité principale du commerçant (03/11/2026) ; « Autre activité » : précision libre.
            'secteur_activite_id' => ['required', 'integer', Rule::exists('secteurs_activite', 'id')->where('actif', true)],
            'secteur_activite_autre' => ['nullable', 'string', 'max:120'],
            // IFU et attestation : personne physique, facultatifs depuis le 05/11/2026 (sans IFU : formalisation différée et
            // récépissé) ; donnés, ils vont ensemble.
            'ifu' => $attestation ? Ifu::regles($user, 'nullable') : ['nullable'],
            'attestation_ifu' => [Rule::requiredIf($attestation && filled($request->input('ifu'))), 'nullable', ...$document],
            // Carte de commerçant : personne à prévenir ; groupe sanguin facultatif, imprimé seulement avec consentement.
            'personne_prevenir_nom' => ['required', 'string', 'min:2', 'max:150'],
            'personne_prevenir_telephone' => ['required', 'string', 'max:20', fn (string $a, mixed $v, \Closure $echec) => Telephone::estValide((string) $v) ? null : $echec(__('public.boutiques.erreurs.prevenir_telephone'))],
            'groupe_sanguin' => ['nullable', Rule::in(User::GROUPES_SANGUINS)],
            'consentement_groupe_sanguin' => [filled($request->input('groupe_sanguin')) ? 'accepted' : 'nullable'],
        ], [
            'secteur_activite_id.*' => __('public.boutiques.erreurs.secteur'),
            'sexe.*' => __('public.verification.erreurs.sexe'),
            'lieu_naissance.*' => __('public.verification.erreurs.lieu_naissance'),
            'ifu.required' => __('public.boutiques.erreurs.sans_ifu'),
            'attestation_ifu.*' => __('public.boutiques.erreurs.attestation_ifu'),
            'personne_prevenir_nom.*' => __('public.boutiques.erreurs.prevenir_nom'),
            'personne_prevenir_telephone.*' => __('public.boutiques.erreurs.prevenir_telephone'),
            'groupe_sanguin.*' => __('public.boutiques.erreurs.groupe_sanguin'),
            'consentement_groupe_sanguin.*' => __('public.boutiques.erreurs.consentement_groupe_sanguin'),
        ], __('public.boutiques.reservation.attributs'));
        $secteur = SecteurActivite::query()->findOrFail($donnees['secteur_activite_id']);
        $autre = $secteur->code === SecteurActivite::AUTRE ? trim((string) ($donnees['secteur_activite_autre'] ?? '')) : null;
        if ($autre === '') {
            throw ValidationException::withMessages(['secteur_activite_autre' => __('public.boutiques.erreurs.secteur_autre')]);
        }

        $inscription = $this->listes->inscrire(
            $campagne, $request->user(), $boutique, isset($donnees['type_piece']) ? TypeDocumentKyc::from($donnees['type_piece']) : null, $donnees['numero_piece'] ?? null,
            $request->file('piece'), $donnees['engagements'] ?? [], (string) $request->ip(), (string) $request->userAgent(),
            $donnees['ifu'] ?? null, $request->file('attestation_ifu'),
        );
        $user->forceFill(array_filter(['sexe' => $donnees['sexe'] ?? null, 'lieu_naissance' => isset($donnees['lieu_naissance']) ? trim($donnees['lieu_naissance']) : null]))->forceFill([
            'secteur_activite_id' => $secteur->id, 'secteur_activite_autre' => $autre,
            'personne_prevenir_nom' => trim($donnees['personne_prevenir_nom']),
            'personne_prevenir_telephone' => Telephone::normaliser($donnees['personne_prevenir_telephone']),
        ])->save();
        // Groupe sanguin (donnée de santé, accord daté) ; son justificatif se dépose dans « Vérifier mon identité ».
        app(JustificatifsGroupeSanguin::class)->enregistrerGroupe($user, $donnees['groupe_sanguin'] ?? null);
        // Données imprimées sur la carte de commerçant en cours : nouvelle version.
        if (Cartes::courante($user, TypeCarte::Commercant)) {
            app(Cartes::class)->synchroniser($user, TypeCarte::Commercant, $user);
        }

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
