<?php

namespace App\Models;

use App\Contracts\Parametrable;
use App\Enums\CompteDestination;
use App\Enums\StatutCampagneBoutiques;
use App\Enums\UsageMoyenPaiement;
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
    'statut', 'nom', 'description', 'date_ouverture', 'date_cloture', 'delai_paiement_valeur', 'delai_paiement_duree_id', 'delai_paiement_secondes', 'canaux_actifs', 'max_boutiques_par_personne', 'sites',
    'version', 'cree_par', 'soumis_par', 'soumise_le', 'valide_par', 'validee_le', 'motif_rejet',
    'ouverte_le', 'cloturee_le', 'attribuee_le',
])]
class CampagneBoutiques extends Model implements Parametrable
{
    public const PARAMETRES = ['nom', 'description', 'date_ouverture', 'date_cloture', 'sites', 'max_boutiques_par_personne', 'canaux_actifs'];

    /** Valeurs par défaut d'une nouvelle campagne. */
    public const MAX_PAR_PERSONNE = 1;

    protected function casts(): array
    {
        return [
            'statut' => StatutCampagneBoutiques::class,
            'date_ouverture' => 'datetime',
            'date_cloture' => 'datetime',
            'delai_paiement_valeur' => 'integer',
            'delai_paiement_duree_id' => 'integer',
            'delai_paiement_secondes' => 'integer',
            'max_boutiques_par_personne' => 'integer',
            'canaux_actifs' => 'array',
            'sites' => 'array',
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

    /** La campagne garde son propre délai (ancien paramètre de campagne, converti). */
    public function aSonPropreDelai(): bool
    {
        return (bool) $this->delai_paiement_secondes;
    }

    public static function ouverte(): ?self
    {
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

    /** Pas d'avenant : une campagne confirmée se rejette ou se recrée. */
    public function champsAvenantAutorises(): ?array
    {
        return [];
    }

    public function appliquerAvenant(array $valeurs): void {}

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

        return $snapshot;
    }

    /** @return \Illuminate\Database\Eloquent\Collection<int, Site> sites concernés par la campagne */
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

    /** Réservations ouvertes : campagne au statut OUVERTE et dans sa période (date et heure). */
    public function accepteReservations(): bool
    {
        return $this->statut === StatutCampagneBoutiques::Ouverte && now()->between($this->date_ouverture, $this->date_cloture);
    }

    /**
     * Boutiques par statut sur les sites de la campagne (tableau de bord) : disponibles, pré-réservées, réservées, attribuées.
     *
     * @return array{disponible: int, pre_reservee: int, reservee: int, attribuee: int}
     */
    public function compteurs(): array
    {
        $parStatut = Boutique::query()->whereIn('site_id', (array) $this->sites)->whereHas('type', fn ($q) => $q->where('attribuable', true))
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
