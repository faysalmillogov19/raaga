<?php

namespace App\Models;

use App\Contracts\Parametrable;
use App\Enums\CompteDestination;
use App\Enums\StatutCampagneBoutiques;
use App\Enums\UsageMoyenPaiement;
use App\Services\Boutiques\EcheancesCampagnes;
use App\Services\Paiement\OuverturePaiements;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Collection;

/**
 * Campagne de réservation des boutiques, au premier arrivé, premier servi (aucune priorité, aucun tirage). Indépendante
 * des actions. Paramètres : période d'ouverture (date et heure), site(s) concerné(s), nombre maximum de boutiques par
 * personne (1 par défaut), moyens de paiement. Délai de paiement de la caution : celui des « Délais de paiement »
 * (72 heures par défaut), sauf pour une campagne qui avait son propre délai (gardé, en valeur + durée). Caution et avance de
 * chaque boutique : grille tarifaire confirmée de son site. Workflow commun (RÉGULATEUR saisit, VALIDATEUR confirme).
 */
#[Table(name: 'campagnes_boutiques')]
#[Fillable([
    'statut', 'nom', 'description', 'date_ouverture', 'date_cloture', 'delai_paiement_valeur', 'delai_paiement_duree_id', 'delai_paiement_secondes', 'canaux_actifs', 'sites', 'types', 'limites',
    'delai_kyc_valeur', 'delai_kyc_duree_id', 'delai_kyc_secondes', 'delai_relance_valeur', 'delai_relance_duree_id', 'delai_relance_secondes',
    'version', 'cree_par', 'soumis_par', 'soumise_le', 'valide_par', 'validee_le', 'motif_rejet',
    'ouverte_le', 'cloturee_le', 'attribuee_le',
])]
class CampagneBoutiques extends Model implements Parametrable
{
    public const PARAMETRES = ['nom', 'description', 'date_ouverture', 'date_cloture', 'sites', 'types', 'limites', 'canaux_actifs',
        'delai_kyc_valeur', 'delai_kyc_duree_id', 'delai_kyc_secondes', 'delai_relance_valeur', 'delai_relance_duree_id', 'delai_relance_secondes'];

    /**
     * Avenant d'une campagne ouverte (réservations en cours) : date de clôture, présentation, délais des listes
     * d'attente, moyens de paiement, et ajouts au périmètre (sites, types, limites relevées ; jamais de retrait).
     */
    public const AVENANT_OUVERTE = ['date_cloture', 'description', 'canaux_actifs', 'sites', 'types', 'limites',
        'delai_kyc_valeur', 'delai_kyc_duree_id', 'delai_kyc_secondes', 'delai_relance_valeur', 'delai_relance_duree_id', 'delai_relance_secondes'];

    /**
     * Délais des listes d'attente : clé => [valeur, durée] par défaut. « kyc » : correction de l'identité d'un
     * attributaire ; « relance » : nouvelle manifestation d'intérêt de la liste d'attente générale.
     */
    public const DELAIS_LISTES = ['kyc' => [7, 'JOUR'], 'relance' => [7, 'JOUR']];

    /** Valeurs par défaut d'une nouvelle campagne. */
    /** Nombre maximum d'emplacements par personne, pour chaque type coché (par défaut). */
    public const LIMITE_PAR_TYPE = 1;

    protected function casts(): array
    {
        return [
            'statut' => StatutCampagneBoutiques::class,
            'date_ouverture' => 'datetime',
            'date_cloture' => 'datetime',
            'delai_paiement_valeur' => 'integer',
            'delai_paiement_duree_id' => 'integer',
            'delai_paiement_secondes' => 'integer',
            'limites' => 'array',
            'canaux_actifs' => 'array',
            'sites' => 'array',
            'types' => 'array',
            'version' => 'integer',
            'soumise_le' => 'datetime',
            'validee_le' => 'datetime',
            'ouverte_le' => 'datetime',
            'cloturee_le' => 'datetime',
            'attribuee_le' => 'datetime',
        ];
    }

    /** Délai de paiement de la caution, en secondes : celui de la campagne s'il en a un, sinon celui des paramètres. */
    public function delaiPaiementSecondes(): int
    {
        return $this->delai_paiement_secondes ?: ParametresPaiements::delaiSecondes('caution');
    }

    /** Délai de paiement de la caution, lisible (« 72 heures »). */
    public function libelleDelaiPaiement(): string
    {
        $duree = Duree::parId($this->delai_paiement_duree_id);

        return $duree && $this->delai_paiement_valeur ? $duree->libelle($this->delai_paiement_valeur) : ParametresPaiements::libelleEnVigueur('caution');
    }

    /** Délai d'une liste d'attente (kyc, relance), en secondes : celui saisi pour la campagne, sinon la valeur par défaut. */
    public function delaiListeSecondes(string $delai): int
    {
        [$valeur, $duree] = self::DELAIS_LISTES[$delai];

        return (int) $this->getAttribute('delai_'.$delai.'_secondes') ?: Duree::parCode($duree)->enSecondes($valeur);
    }

    /** Délai d'une liste d'attente, lisible (« 7 jours »). */
    public function libelleDelaiListe(string $delai): string
    {
        [$valeur, $duree] = self::DELAIS_LISTES[$delai];
        $saisie = Duree::parId($this->getAttribute('delai_'.$delai.'_duree_id'));
        $n = (int) $this->getAttribute('delai_'.$delai.'_valeur');

        return $saisie && $n ? $saisie->libelle($n) : Duree::parCode($duree)->libelle($valeur);
    }

    /** La campagne garde son propre délai (ancien paramètre de campagne, converti). */
    public function aSonPropreDelai(): bool
    {
        return (bool) $this->delai_paiement_secondes;
    }

    /** Campagne ouverte, après application des échéances (ouverture et clôture automatiques à leurs dates). */
    public static function ouverte(): ?self
    {
        app(EcheancesCampagnes::class)->appliquer();

        return self::query()->where('statut', StatutCampagneBoutiques::Ouverte)->latest('date_ouverture')->first();
    }

    /**
     * Moyens retenus par la campagne et autorisés pour la caution (actifs et confirmés au référentiel).
     *
     * @return Collection<int, MoyenPaiement>
     */
    public function moyensCaution(): Collection
    {
        return MoyenPaiement::proposables()->filter(fn (MoyenPaiement $m) => in_array($m->code, (array) $this->canaux_actifs, true)
            && $m->usage(UsageMoyenPaiement::Caution)['autorise'])->values();
    }

    /** Raison pour laquelle aucun moyen de paiement de la caution n'est ouvert, ou null si au moins un l'est. */
    public function raisonPaiementsFermes(): ?string
    {
        return OuverturePaiements::raisonFermeture(CompteDestination::Location, $this->moyensCaution());
    }

    /**
     * Moyens retenus par la campagne, autorisés pour la caution et dont les plafonds couvrent le montant.
     *
     * @return list<MoyenPaiement>
     */
    public function canaux(int $montant): array
    {
        return MoyenPaiement::pourPaiement((array) $this->canaux_actifs, UsageMoyenPaiement::Caution, $montant);
    }

    public function typeParametrage(): string
    {
        return 'campagne_boutiques';
    }

    public function estModifiable(): bool
    {
        return $this->statut === StatutCampagneBoutiques::Brouillon;
    }

    public function estSoumis(): bool
    {
        return $this->statut === StatutCampagneBoutiques::Soumise;
    }

    public function marquerSoumis(User $auteur): void
    {
        $this->forceFill(['statut' => StatutCampagneBoutiques::Soumise, 'soumis_par' => $auteur->id, 'soumise_le' => now(), 'motif_rejet' => null])->save();
    }

    public function marquerConfirme(User $validateur): void
    {
        $this->forceFill(['statut' => StatutCampagneBoutiques::Confirmee, 'valide_par' => $validateur->id, 'validee_le' => now()])->save();
    }

    /** Retiré de la validation par son auteur, avant toute décision : retour au brouillon. */
    public function marquerRetire(): void
    {
        $this->forceFill(['statut' => StatutCampagneBoutiques::Brouillon])->save();
    }

    public function marquerRejete(string $motif): void
    {
        $this->forceFill(['statut' => StatutCampagneBoutiques::Brouillon, 'motif_rejet' => $motif])->save();
    }

    /**
     * Avenant : confirmée (aucune réservation), tous les paramètres ; ouverte, ceux de AVENANT_OUVERTE (les ajouts au
     * périmètre sont contrôlés par le contrôleur) ; ensuite, aucun.
     */
    public function champsAvenantAutorises(): ?array
    {
        return match ($this->statut) {
            StatutCampagneBoutiques::Confirmee => self::PARAMETRES,
            StatutCampagneBoutiques::Ouverte => self::AVENANT_OUVERTE,
            default => [],
        };
    }

    public function appliquerAvenant(array $valeurs): void
    {
        $this->fill(array_intersect_key($valeurs, array_flip(self::PARAMETRES)))->save();
    }

    public function creerVersion(User $auteur, ?string $motif = null): CampagneBoutiquesVersion
    {
        $numero = (int) $this->versions()->max('numero') + 1;

        $version = CampagneBoutiquesVersion::create([
            'campagne_boutiques_id' => $this->id,
            'numero' => $numero,
            'parametres' => $this->snapshotParametres(),
            'auteur_id' => $auteur->id,
            'motif' => $motif,
            'created_at' => now(),
        ]);

        $this->forceFill(['version' => $numero])->save();

        return $version;
    }

    /**
     * Paramètres figés : champs de la campagne, sites en clair (pour le différentiel).
     *
     * @return array<string, mixed>
     */
    public function snapshotParametres(): array
    {
        $snapshot = [];
        foreach (self::PARAMETRES as $champ) {
            $valeur = $this->getAttribute($champ);
            $snapshot[$champ] = $valeur instanceof \DateTimeInterface ? $valeur->format(DATE_ATOM) : $valeur;
        }
        $snapshot['sites'] = $this->sitesConcernes()->map(fn (Site $s) => ($s->code ? $s->code.' · ' : '').$s->nom)->implode(', ');
        // Types proposés, lisibles dans le différentiel du VALIDATEUR (« BOUT · Boutique, PP · Pôle : préparation… »).
        $snapshot['types'] = $this->typesConcernes()->map(fn (TypeBoutique $t) => ($t->code ? $t->code.' · ' : '').$t->libelle)->implode(', ');
        // Limites par type, lisibles dans le différentiel du VALIDATEUR (« BOUT : 1, PV : 1 »).
        $snapshot['limites'] = $this->typesConcernes()->map(fn (TypeBoutique $t) => ($t->code ?? $t->libelle).' : '.$this->limitePour($t->id))->implode(', ');

        return $snapshot;
    }

    /** @return \Illuminate\Database\Eloquent\Collection<int, Site> sites concernés par la campagne */
    /** Nombre maximum d'emplacements de ce type par personne dans la campagne (1 si non précisé). */
    public function limitePour(int $typeId): int
    {
        return max(1, (int) (((array) $this->limites)[(string) $typeId] ?? self::LIMITE_PAR_TYPE));
    }

    /** @return \Illuminate\Database\Eloquent\Collection<int, TypeBoutique> types proposés par la campagne */
    public function typesConcernes(): \Illuminate\Database\Eloquent\Collection
    {
        return TypeBoutique::query()->whereIn('id', (array) $this->types)->orderBy('ordre')->orderBy('libelle')->get();
    }

    public function sitesConcernes(): \Illuminate\Database\Eloquent\Collection
    {
        return Site::query()->whereIn('id', (array) $this->sites)->orderBy('nom')->get();
    }

    /**
     * @return HasMany<ReservationBoutique, $this>
     */
    public function reservations(): HasMany
    {
        return $this->hasMany(ReservationBoutique::class, 'campagne_boutiques_id');
    }

    /**
     * État affiché au public (page « Obtenir une boutique ») :
     *  - « bientot » : confirmée, ou ouverte avant sa date d'ouverture ; compte à rebours si cette date est à venir ;
     *  - « ouverte » : réservations possibles maintenant ;
     *  - « terminee » : clôturée, ou ouverte mais sa période est passée.
     * Une campagne en brouillon ou soumise n'est jamais montrée au public.
     */
    public function etatPublic(): string
    {
        return match (true) {
            $this->accepteReservations() => 'ouverte',
            $this->statut === StatutCampagneBoutiques::Confirmee,
            $this->statut === StatutCampagneBoutiques::Ouverte && $this->date_ouverture?->isFuture() => 'bientot',
            default => 'terminee',
        };
    }

    /** Réservations ouvertes : campagne au statut OUVERTE et dans sa période (date et heure). */
    public function accepteReservations(): bool
    {
        return $this->statut === StatutCampagneBoutiques::Ouverte && now()->between($this->date_ouverture, $this->date_cloture);
    }

    /**
     * Boutiques par statut sur les sites et les types de la campagne (tableau de bord) : disponibles, pré-réservées, réservées, attribuées.
     *
     * @return array{disponible: int, pre_reservee: int, reservee: int, attribuee: int}
     */
    public function compteurs(): array
    {
        $parStatut = Boutique::query()->whereIn('site_id', (array) $this->sites)->whereIn('type_boutique_id', (array) $this->types)
            ->whereHas('type', fn ($q) => $q->where('attribuable', true))
            ->selectRaw('statut, count(*) as n')->groupBy('statut')->pluck('n', 'statut');
        $n = fn (array $statuts) => (int) collect($statuts)->sum(fn ($s) => $parStatut[$s] ?? 0);

        return ['disponible' => $n(['DISPONIBLE', 'EN_CAMPAGNE']), 'pre_reservee' => $n(['PRE_RESERVEE']), 'reservee' => $n(['RESERVEE']), 'attribuee' => $n(['ATTRIBUEE'])];
    }

    /**
     * @return HasMany<CampagneBoutiquesVersion, $this>
     */
    public function versions(): HasMany
    {
        return $this->hasMany(CampagneBoutiquesVersion::class, 'campagne_boutiques_id')->orderBy('numero');
    }

    public function avenants(): MorphMany
    {
        return $this->morphMany(Avenant::class, 'avenantable')->latest('id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function createur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cree_par');
    }
}
