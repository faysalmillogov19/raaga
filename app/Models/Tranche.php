<?php

namespace App\Models;

use App\Contracts\Parametrable;
use App\Domain\Allocation\ParametresAllocation;
use App\Enums\MethodeAllocation;
use App\Enums\ModeSaisie;
use App\Enums\PayeurFrais;
use App\Enums\RegleArret;
use App\Enums\RegleSiEchec;
use App\Enums\StatutTranche;
use App\Enums\TypeFraisSouscription;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

#[Fillable([
    'statut', 'nom', 'valeur_unitaire', 'min_actions_par_souscription', 'plafond_actions_par_personne',
    'objectif_montant', 'date_ouverture', 'date_cloture', 'publics_autorises', 'mode_saisie',
    'souscriptions_multiples', 'regle_arret', 'pourcentage_depassement_max', 'methode_allocation',
    'seuil_garanti_petits', 'plafond_concentration_pct', 'seuil_reussite_montant', 'regle_si_echec',
    'jours_prolongation', 'delai_remboursement_jours', 'frais_paiement_supportes_par',
    'frais_souscription_type', 'frais_souscription_valeur', 'canaux_actifs', 'delai_paiement_heures',
    'seuil_kyc_renforce', 'seuil_revue_conformite', 'version', 'cree_par', 'soumis_par', 'soumise_le',
    'valide_par', 'validee_le', 'motif_rejet', 'ouverte_le', 'cloturee_le', 'allouee_le', 'annulee_le',
])]
class Tranche extends Model implements Parametrable
{
    use HasFactory;

    /**
     * Paramètres métier versionnés (snapshot dans TrancheVersion, verrouillés à l'ouverture).
     */
    public const PARAMETRES = [
        'nom', 'valeur_unitaire', 'min_actions_par_souscription', 'plafond_actions_par_personne',
        'objectif_montant', 'date_ouverture', 'date_cloture', 'publics_autorises', 'mode_saisie',
        'souscriptions_multiples', 'regle_arret', 'pourcentage_depassement_max', 'methode_allocation',
        'seuil_garanti_petits', 'plafond_concentration_pct', 'seuil_reussite_montant', 'regle_si_echec',
        'jours_prolongation', 'delai_remboursement_jours', 'frais_paiement_supportes_par',
        'frais_souscription_type', 'frais_souscription_valeur', 'canaux_actifs', 'delai_paiement_heures',
        'seuil_kyc_renforce', 'seuil_revue_conformite',
    ];

    protected function casts(): array
    {
        return [
            'statut' => StatutTranche::class,
            'valeur_unitaire' => 'integer',
            'min_actions_par_souscription' => 'integer',
            'plafond_actions_par_personne' => 'integer',
            'objectif_montant' => 'integer',
            'date_ouverture' => 'datetime',
            'date_cloture' => 'datetime',
            'publics_autorises' => 'array',
            'mode_saisie' => ModeSaisie::class,
            'souscriptions_multiples' => 'boolean',
            'regle_arret' => RegleArret::class,
            'pourcentage_depassement_max' => 'integer',
            'methode_allocation' => MethodeAllocation::class,
            'seuil_garanti_petits' => 'integer',
            'plafond_concentration_pct' => 'integer',
            'seuil_reussite_montant' => 'integer',
            'regle_si_echec' => RegleSiEchec::class,
            'jours_prolongation' => 'integer',
            'delai_remboursement_jours' => 'integer',
            'frais_paiement_supportes_par' => PayeurFrais::class,
            'frais_souscription_type' => TypeFraisSouscription::class,
            'frais_souscription_valeur' => 'integer',
            'canaux_actifs' => 'array',
            'delai_paiement_heures' => 'integer',
            'seuil_kyc_renforce' => 'integer',
            'seuil_revue_conformite' => 'integer',
            'version' => 'integer',
            'soumise_le' => 'datetime',
            'validee_le' => 'datetime',
            'ouverte_le' => 'datetime',
            'cloturee_le' => 'datetime',
            'allouee_le' => 'datetime',
            'annulee_le' => 'datetime',
        ];
    }

    public function typeParametrage(): string
    {
        return 'tranche';
    }

    /**
     * Les paramètres ne sont modifiables qu'en brouillon ; ensuite, uniquement par avenant.
     */
    public function estModifiable(): bool
    {
        return $this->statut === StatutTranche::Brouillon;
    }

    public function estSoumis(): bool
    {
        return $this->statut === StatutTranche::Soumise;
    }

    public function marquerSoumis(User $auteur): void
    {
        $this->forceFill(['statut' => StatutTranche::Soumise, 'soumis_par' => $auteur->id, 'soumise_le' => now(), 'motif_rejet' => null])->save();
    }

    public function marquerConfirme(User $validateur): void
    {
        $this->forceFill(['statut' => StatutTranche::Confirmee, 'valide_par' => $validateur->id, 'validee_le' => now()])->save();
    }

    public function marquerRejete(string $motif): void
    {
        $this->forceFill(['statut' => StatutTranche::Brouillon, 'motif_rejet' => $motif])->save();
    }

    /**
     * Confirmée (pas encore ouverte) : tous les paramètres ; ouverte : la date de clôture seulement.
     *
     * @return list<string>|null
     */
    public function champsAvenantAutorises(): ?array
    {
        return match ($this->statut) {
            StatutTranche::Confirmee => null,
            StatutTranche::Ouverte => ['date_cloture'],
            default => [],
        };
    }

    public function appliquerAvenant(array $valeurs): void
    {
        $this->fill(array_intersect_key($valeurs, array_flip(self::PARAMETRES)))->save();
    }

    public function creerVersion(User $auteur, ?string $motif = null): TrancheVersion
    {
        $numero = (int) $this->versions()->max('numero') + 1;

        $version = TrancheVersion::create([
            'tranche_id' => $this->id,
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
     * Snapshot sérialisable des paramètres métier (pour TrancheVersion et le diff).
     *
     * @return array<string, mixed>
     */
    public function snapshotParametres(): array
    {
        $snapshot = [];

        foreach (self::PARAMETRES as $champ) {
            $valeur = $this->getAttribute($champ);

            $snapshot[$champ] = match (true) {
                $valeur instanceof \BackedEnum => $valeur->value,
                $valeur instanceof \DateTimeInterface => $valeur->format(DATE_ATOM),
                default => $valeur,
            };
        }

        return $snapshot;
    }

    /**
     * Paramètres d'entrée du moteur d'allocation.
     */
    public function parametresAllocation(?MethodeAllocation $methode = null): ParametresAllocation
    {
        return new ParametresAllocation(
            valeurUnitaire: $this->valeur_unitaire,
            objectifMontant: $this->objectif_montant,
            methode: $methode ?? $this->methode_allocation,
            regleArret: $this->regle_arret,
            pourcentageDepassementMax: $this->pourcentage_depassement_max ?? 0,
            seuilGarantiPetits: $this->seuil_garanti_petits ?? 0,
            plafondActionsParPersonne: $this->plafond_actions_par_personne,
            plafondConcentrationPct: $this->plafond_concentration_pct,
            souscriptionsMultiples: $this->souscriptions_multiples,
        );
    }

    /**
     * @return HasMany<TrancheVersion, $this>
     */
    public function versions(): HasMany
    {
        return $this->hasMany(TrancheVersion::class)->orderBy('numero');
    }

    public function avenants(): MorphMany
    {
        return $this->morphMany(Avenant::class, 'avenantable')->latest('id');
    }

    /**
     * @return HasMany<Souscription, $this>
     */
    public function souscriptions(): HasMany
    {
        return $this->hasMany(Souscription::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function createur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cree_par');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function validateur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'valide_par');
    }
}
