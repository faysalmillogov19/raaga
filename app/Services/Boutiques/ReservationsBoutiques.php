<?php

namespace App\Services\Boutiques;

use App\Enums\DocumentAccepte;
use App\Enums\FaceDocument;
use App\Enums\StatutBoutique;
use App\Enums\StatutDocumentKyc;
use App\Enums\StatutKyc;
use App\Enums\StatutReservationBoutique;
use App\Enums\TypeDocumentKyc;
use App\Enums\UniteLoyer;
use App\Models\Acceptation;
use App\Models\Boutique;
use App\Models\CampagneBoutiques;
use App\Models\GrilleTarifaire;
use App\Models\GrilleTarifaireLigne;
use App\Models\KycDocument;
use App\Models\ReservationBoutique;
use App\Models\TypeBoutique;
use App\Models\User;
use App\Services\Audit\Journal;
use App\Services\Kyc\VerificationIdentite;
use App\Support\MemoRequete;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Réservation des boutiques au premier arrivé, premier servi (aucune priorité, aucun tirage).
 *
 * Garanties en base, pas seulement à l'écran :
 *  - une seule réservation active par boutique (index unique partiel) : de deux demandes simultanées, une seule passe ;
 *  - les réservations d'une campagne passent une par une (verrou sur la campagne) : le premier est celui dont la
 *    confirmation est enregistrée en premier par le serveur, et la limite par personne (compte et numéro de pièce)
 *    se contrôle dans la même transaction ;
 *  - aucune réservation avant l'ouverture ni après la fermeture (date et heure).
 * La boutique est pré-réservée au nom du citoyen pour le délai de paiement de la caution ; payée, elle est réservée.
 */
class ReservationsBoutiques
{
    public const ENGAGEMENTS = [
        DocumentAccepte::CautionSansLienActions,
        DocumentAccepte::ReglementCampagneBoutiques,
        DocumentAccepte::OrigineLiciteFonds,
    ];

    /** Pièces acceptées à la réservation (le RCCM s'ajoute au NIF, facultatif). */
    public const PIECES = [TypeDocumentKyc::Cnib, TypeDocumentKyc::Passeport, TypeDocumentKyc::Nif];

    public function __construct(private readonly Journal $journal) {}

    /**
     * Boutiques réservables d'une campagne : sur ses sites et de ses types (attribuables, actifs et validés au
     * référentiel ; jamais la chambre froide mutualisée).
     */
    private static function requeteReservables(CampagneBoutiques $campagne)
    {
        return Boutique::query()->whereIn('site_id', (array) $campagne->sites)->whereIn('type_boutique_id', (array) $campagne->types)
            ->whereHas('type', fn ($q) => $q->proposables());
    }

    /** @return Collection<int, TypeBoutique> types de la campagne présents sur un site (filtre du plan) */
    public static function typesDuSite(CampagneBoutiques $campagne, int $siteId): Collection
    {
        $ids = self::requeteReservables($campagne)->where('site_id', $siteId)->distinct()->pluck('type_boutique_id');

        return TypeBoutique::query()->whereIn('id', $ids)->orderBy('ordre')->orderBy('libelle')->get();
    }

    /**
     * Niveaux et blocs d'un site de la campagne : [niveau => [bloc, …]], dans l'ordre des niveaux.
     *
     * @return Collection<string, list<string>>
     */
    public static function niveauxEtBlocs(CampagneBoutiques $campagne, int $siteId, ?int $typeId = null): Collection
    {
        return self::requeteReservables($campagne)->where('site_id', $siteId)->when($typeId, fn ($q) => $q->where('type_boutique_id', $typeId))->with('niveauRef')->get(['id', 'site_id', 'niveau', 'bloc'])
            ->sortBy(fn (Boutique $b) => [$b->niveauRef?->rang ?? 99, $b->bloc])
            ->groupBy('niveau')->map(fn ($groupe) => $groupe->pluck('bloc')->unique()->values()->all());
    }

    /**
     * Plan schématique d'un bloc : vrais codes, statut, type, surface ; aucune donnée personnelle.
     *
     * @return Collection<int, Boutique>
     */
    public static function plan(CampagneBoutiques $campagne, int $siteId, string $niveau, string $bloc, ?int $typeId = null): Collection
    {
        return self::requeteReservables($campagne)->where('site_id', $siteId)->where('niveau', $niveau)->where('bloc', $bloc)
            ->when($typeId, fn ($q) => $q->where('type_boutique_id', $typeId))
            ->with(['type', 'site', 'niveauRef'])->get(['id', 'code', 'site_id', 'niveau', 'bloc', 'numero', 'surface', 'type_boutique_id', 'statut', 'observations'])
            // Ordre naturel des numéros (2 avant 10), quelle que soit leur écriture (2, 002).
            ->sortBy('numero', SORT_NATURAL)->values();
    }

    /**
     * Réservations actives (pré-réservées, réservées, attribuées) d'un type dans une campagne, pour une personne
     * identifiée par son compte ou par l'un de ses numéros de pièce. Annulées et expirées ne comptent pas.
     *
     * @param  list<string>  $pieces  numéros de pièce normalisés
     */
    public static function activesDuType(CampagneBoutiques $campagne, int $typeId, int $userId, array $pieces): int
    {
        return ReservationBoutique::query()->where('campagne_boutiques_id', $campagne->id)->whereIn('statut', StatutReservationBoutique::actifs())
            ->whereHas('boutique', fn ($b) => $b->where('type_boutique_id', $typeId))
            ->where(fn ($q) => $q->where('user_id', $userId)->when(array_filter($pieces), fn ($q, $p) => $q->orWhereIn('numero_piece', $p)))->count();
    }

    /**
     * Ce qui reste au citoyen dans la campagne, par type : limite moins ses réservations actives (compte et numéros de
     * pièce déjà utilisés dans la campagne).
     *
     * @return array<int, int> type => nombre encore réservable (0 : limite atteinte)
     */
    public static function restants(CampagneBoutiques $campagne, User $user): array
    {
        $pieces = ReservationBoutique::query()->where('campagne_boutiques_id', $campagne->id)->where('user_id', $user->id)
            ->whereNotNull('numero_piece')->distinct()->pluck('numero_piece')->all();

        return collect((array) $campagne->types)->mapWithKeys(fn ($id) => [(int) $id => max(0,
            $campagne->limitePour((int) $id) - self::activesDuType($campagne, (int) $id, $user->id, $pieces))])->all();
    }

    /**
     * Détail d'un emplacement (plan, récapitulatif, fiche du back-office) : code, site, niveau, bloc ou zone, type
     * (sigle, libellé complet, description pour le citoyen), surface, observations ; montants (loyer mensuel, caution,
     * avance, total à payer) seulement si demandés (citoyen), jamais pour le GESTIONNAIRE.
     *
     * @return array<string, mixed>
     */
    public static function detail(Boutique $b, bool $montants = true): array
    {
        $tarif = $montants ? self::tarifer($b) : null;

        return [
            'code' => $b->code,
            'site' => $b->site ? ($b->site->code ? $b->site->code.' · ' : '').$b->site->nom : null,
            'niveau' => $b->niveau ? $b->niveau.' · '.$b->libelleNiveau() : null,
            'bloc' => $b->bloc,
            'type_code' => $b->type?->code,
            'type' => $b->type?->libelle,
            'type_description' => $b->type?->description_citoyen,
            'surface' => $b->surface ? format_nombre((float) $b->surface, 2).' m²' : null,
            'observations' => $b->observations,
            'montants' => $tarif ? [
                'loyer' => format_fcfa($tarif['loyer_mensuel']), 'caution' => format_fcfa($tarif['caution']),
                'avance' => format_fcfa($tarif['avance']), 'total' => format_fcfa($tarif['caution'] + $tarif['avance']),
            ] : null,
        ];
    }

    /**
     * Montants d'une boutique selon la grille confirmée de son site ; null si la grille ou la ligne manque.
     *
     * @return array{loyer_mensuel: int, caution: int, avance: int, mois_caution: int, mois_avance: int}|null
     */
    public static function tarifer(Boutique $boutique): ?array
    {
        if ($boutique->niveau === null || $boutique->surface === null) {
            return null;
        }
        // Grille confirmée lue une fois par site et par requête (plan d'un bloc entier).
        $grille = MemoRequete::retenir('grille_confirmee.'.$boutique->site_id, fn () => GrilleTarifaire::query()->where('site_id', $boutique->site_id)->first()?->confirmee());
        $ligne = $grille['lignes'][GrilleTarifaire::cle($boutique->niveau, $boutique->type_boutique_id)] ?? null;
        if ($ligne === null) {
            return null;
        }
        $unite = UniteLoyer::depuis($ligne['unite_loyer'] ?? null)
            ?? GrilleTarifaireLigne::query()->whereHas('grille', fn ($q) => $q->where('site_id', $boutique->site_id))
                ->where('niveau', $boutique->niveau)->where('type_boutique_id', $boutique->type_boutique_id)->first()?->modeCalcul()
            ?? UniteLoyer::M2;

        return ['mois_caution' => (int) $ligne['mois_caution'], 'mois_avance' => (int) $ligne['mois_avance'],
            ...GrilleTarifaire::montants((float) $boutique->surface, $ligne['loyer_m2'], $ligne['mois_caution'], $ligne['mois_avance'], $unite)];
    }

    /**
     * Pré-réservation d'une boutique disponible, avec la pièce d'identité (CNIB, passeport ou NIF ; RCCM facultatif).
     *
     * @param  list<string>  $engagements  valeurs DocumentAccepte cochées
     *
     * @throws ValidationException
     */
    public function reserver(CampagneBoutiques $campagne, User $user, Boutique $boutique, TypeDocumentKyc $typePiece, string $numeroPiece,
        UploadedFile $piece, ?UploadedFile $rccm, array $engagements, string $ip, string $userAgent): ReservationBoutique
    {
        $erreur = fn (string $cle, array $params = []) => throw ValidationException::withMessages(['boutique' => __('public.boutiques.erreurs.'.$cle, $params)]);
        $numero = ReservationBoutique::normaliserPiece($numeroPiece);
        if (! $user->telephone) {
            $erreur('telephone');
        }
        if (! in_array($typePiece, self::PIECES, true) || mb_strlen($numero) < 5) {
            throw ValidationException::withMessages(['numero_piece' => __('public.boutiques.erreurs.piece')]);
        }
        if (array_diff(array_map(fn (DocumentAccepte $d) => $d->value, self::ENGAGEMENTS), $engagements)) {
            throw ValidationException::withMessages(['engagements' => __('public.boutiques.erreurs.engagements')]);
        }

        $chemins = [];
        try {
            return DB::transaction(function () use ($campagne, $user, $boutique, $typePiece, $numero, $piece, $rccm, $ip, $userAgent, $erreur, &$chemins) {
                // Une réservation à la fois par campagne : l'ordre d'arrivée est celui de l'enregistrement par le serveur.
                $campagne = CampagneBoutiques::query()->lockForUpdate()->findOrFail($campagne->id);
                if (! $campagne->accepteReservations()) {
                    $erreur('fermee');
                }
                $boutique = Boutique::query()->lockForUpdate()->with('type')->findOrFail($boutique->id);
                if (! in_array($boutique->site_id, (array) $campagne->sites) || ! in_array($boutique->type_boutique_id, (array) $campagne->types)
                    || ! $boutique->type?->attribuable || $boutique->type->code === TypeBoutique::CODE_CHAMBRE_FROIDE) {
                    $erreur('hors_campagne');
                }
                if (! $boutique->statut->reservable()) {
                    $erreur('deja_prise');
                }
                $tarif = self::tarifer($boutique) ?? $erreur('sans_tarif');

                // Limite par personne et par type : réservations actives de ce type dans la campagne, sur le compte et sur
                // le numéro de pièce. Même transaction, campagne verrouillée : deux demandes simultanées sont traitées l'une
                // après l'autre et ne dépassent jamais la limite.
                $limite = $campagne->limitePour($boutique->type_boutique_id);
                if (self::activesDuType($campagne, $boutique->type_boutique_id, $user->id, [$numero]) >= $limite) {
                    $erreur('limite_type', ['n' => $limite, 'type' => $boutique->type->code ?? $boutique->type->libelle]);
                }

                $reservation = ReservationBoutique::create([
                    'reference' => $this->reference(), 'user_id' => $user->id, 'campagne_boutiques_id' => $campagne->id, 'boutique_id' => $boutique->id,
                    // Montants figés : un changement ultérieur de la grille ne les modifie pas.
                    'caution' => $tarif['caution'], 'avance' => $tarif['avance'], 'loyer_mensuel' => $tarif['loyer_mensuel'],
                    'mois_caution' => $tarif['mois_caution'], 'mois_avance' => $tarif['mois_avance'],
                    'type_piece' => $typePiece->value, 'numero_piece' => $numero,
                    'statut' => StatutReservationBoutique::PreReservee, 'expire_le' => now()->addSeconds($campagne->delaiPaiementSecondes()),
                ]);
                $boutique->changerStatut(StatutBoutique::PreReservee, 'pre_reservation', $reservation, $user);

                foreach (self::ENGAGEMENTS as $document) {
                    Acceptation::create([
                        'user_id' => $user->id, 'reservation_boutique_id' => $reservation->id, 'document' => $document,
                        'version_document' => $document === DocumentAccepte::ReglementCampagneBoutiques ? (string) $campagne->version : config('raaga.inscription.version_conditions'),
                        'accepte_le' => now(), 'ip' => $ip, 'user_agent' => substr($userAgent, 0, 500),
                    ]);
                }

                // Pièce jointe au dossier d'identité du citoyen : vérifiée ensuite par l'ADMIN (vérifications d'identité).
                foreach (array_filter([[$typePiece, FaceDocument::Recto, $piece], $rccm ? [TypeDocumentKyc::Rccm, null, $rccm] : null]) as [$type, $face, $fichier]) {
                    $chemin = $fichier->storeAs('kyc/'.$user->id, Str::uuid().'.'.$fichier->extension(), VerificationIdentite::DISQUE);
                    $chemins[] = $chemin;
                    KycDocument::create([
                        'user_id' => $user->id, 'type' => $type, 'face' => $face, 'chemin_fichier' => $chemin,
                        'nom_original' => Str::limit($fichier->getClientOriginalName(), 200, ''), 'mime' => $fichier->getMimeType(),
                        'taille' => $fichier->getSize(), 'empreinte_sha256' => hash_file('sha256', $fichier->getRealPath()),
                        'statut' => StatutDocumentKyc::EnAttente, 'televerse_par' => $user->id,
                    ]);
                }
                if (in_array($user->statut_kyc, [null, StatutKyc::NonSoumis, StatutKyc::Rejete], true)) {
                    $user->forceFill(['statut_kyc' => StatutKyc::EnAttente])->save();
                }

                $this->journal->ecrire($user, 'reservation_boutique.pre_reservee', 'ReservationBoutique', $reservation->id, null, [
                    'reference' => $reservation->reference, 'boutique' => $boutique->code, 'campagne' => $campagne->id, 'caution' => $tarif['caution'],
                ]);

                return $reservation;
            });
        } catch (UniqueConstraintViolationException) {
            // Deux confirmations simultanées sur la même boutique : l'index unique n'en garde qu'une.
            Storage::disk(VerificationIdentite::DISQUE)->delete($chemins);
            $erreur('deja_prise');
        } catch (\Throwable $e) {
            Storage::disk(VerificationIdentite::DISQUE)->delete($chemins);

            throw $e;
        }
    }

    /** Réservations prêtes à attribuer : réservées (caution payée), avance confirmée ou sans avance, pas encore attribuées. */
    public static function aAttribuer(): Builder
    {
        return ReservationBoutique::query()->where('statut', StatutReservationBoutique::Reservee)
            ->where(fn ($q) => $q->whereNotNull('avance_payee_le')->orWhere('avance', 0));
    }

    /**
     * Avance confirmée (par le circuit de paiement) et date du contrat renseignée : la réservation et la boutique passent
     * « attribuées » (VALIDATEUR). La référence de l'avance est celle du paiement confirmé, jamais saisie à la main.
     */
    public function attribuer(ReservationBoutique $reservation, string $contratSigneLe, User $validateur): void
    {
        DB::transaction(function () use ($reservation, $contratSigneLe, $validateur) {
            $reservation = ReservationBoutique::query()->lockForUpdate()->findOrFail($reservation->id);
            if ($reservation->statut !== StatutReservationBoutique::Reservee) {
                throw ValidationException::withMessages(['contrat_signe_le' => __('admin.boutiques.erreurs.attribution')]);
            }
            if (! $reservation->avanceReglee()) {
                throw ValidationException::withMessages(['contrat_signe_le' => __('admin.boutiques.erreurs.avance_non_confirmee')]);
            }
            $reservation->forceFill(['statut' => StatutReservationBoutique::Attribuee, 'attribuee_le' => now(), 'contrat_signe_le' => $contratSigneLe])->save();
            $reservation->boutique->changerStatut(StatutBoutique::Attribuee, 'attribution', $reservation, $validateur);
            $this->journal->ecrire($validateur, 'reservation_boutique.attribuee', 'ReservationBoutique', $reservation->id, null,
                ['reference_avance' => $reservation->reference_avance, 'contrat_signe_le' => $contratSigneLe]);
        });
    }

    /** « BTQ-7K3M9Q2X » : sans 0/O ni 1/I/L, faciles à confondre au téléphone. */
    private function reference(): string
    {
        do {
            $reference = 'BTQ-'.strtr(Str::upper(Str::random(8)), ['0' => '7', 'O' => 'P', 'I' => 'J', 'L' => 'M', '1' => '3']);
        } while (ReservationBoutique::query()->where('reference', $reference)->exists());

        return $reference;
    }
}
