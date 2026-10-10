<?php

namespace App\Models;

use App\Contracts\Parametrable;
use App\Enums\CanalPaiement;
use App\Enums\ModeCalculDividende;
use App\Enums\PayeurFrais;
use App\Enums\StatutDistribution;
use App\Enums\TraitementNonReclames;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * Paramétrage de la distribution des dividendes d'un exercice (saisi par le RÉGULATEUR,
 * confirmé par le VALIDATEUR, puis mis en paiement par le VALIDATEUR).
 */
#[Table(name: 'distributions_dividendes')]
#[Fillable([
    'exercice', 'statut', 'ag_date', 'ag_pv_numero', 'pv_chemin', 'pv_nom_original', 'pv_empreinte',
    'resultat_distribuable', 'mode_calcul', 'montant_global', 'dividende_brut_par_action',
    'date_arrete', 'date_mise_en_paiement', 'taux_irvm_pb', 'frais_supportes_par', 'seuil_minimal',
    'canaux_autorises', 'canal_defaut', 'tentatives', 'delai_tentatives_heures',
    'conservation_non_reclames_mois', 'traitement_non_reclames', 'version', 'cree_par',
])]
#[Hidden(['pv_chemin'])]
class DistributionDividende extends Model implements Parametrable
{
    /** Paramètres versionnés (le PV est versionné à part, par son empreinte). */
    public const PARAMETRES = [
        'exercice', 'ag_date', 'ag_pv_numero', 'pv_empreinte', 'resultat_distribuable', 'mode_calcul',
        'montant_global', 'dividende_brut_par_action', 'date_arrete', 'date_mise_en_paiement', 'taux_irvm_pb',
        'frais_supportes_par', 'seuil_minimal', 'canaux_autorises', 'canal_defaut', 'tentatives',
        'delai_tentatives_heures', 'conservation_non_reclames_mois', 'traitement_non_reclames',
    ];

    protected function casts(): array
    {
        return [
            'exercice' => 'integer',
            'statut' => StatutDistribution::class,
            'ag_date' => 'date',
            'resultat_distribuable' => 'integer',
            'mode_calcul' => ModeCalculDividende::class,
            'montant_global' => 'integer',
            'dividende_brut_par_action' => 'integer',
            'date_arrete' => 'date',
            'date_mise_en_paiement' => 'date',
            'taux_irvm_pb' => 'integer',
            'frais_supportes_par' => PayeurFrais::class,
            'seuil_minimal' => 'integer',
            'canaux_autorises' => 'array',
            'canal_defaut' => CanalPaiement::class,
            'tentatives' => 'integer',
            'delai_tentatives_heures' => 'integer',
            'conservation_non_reclames_mois' => 'integer',
            'traitement_non_reclames' => TraitementNonReclames::class,
            'version' => 'integer',
            'soumise_le' => 'datetime',
            'confirme_le' => 'datetime',
        ];
    }

    public function typeParametrage(): string
    {
        return 'distribution';
    }

    /**
     * @return array<string, mixed>
     */
    public function snapshotParametres(): array
    {
        $snapshot = [];

        foreach (self::PARAMETRES as $champ) {
            $valeur = $this->getAttribute($champ);

            $snapshot[$champ] = match (true) {
                $valeur instanceof \BackedEnum => $valeur->value,
                $valeur instanceof \DateTimeInterface => $valeur->format('Y-m-d'),
                default => $valeur,
            };
        }

        return $snapshot;
    }

    public function estModifiable(): bool
    {
        return $this->statut === StatutDistribution::Brouillon;
    }

    public function estSoumis(): bool
    {
        return $this->statut === StatutDistribution::Soumis;
    }

    public function marquerSoumis(User $auteur): void
    {
        $this->forceFill(['statut' => StatutDistribution::Soumis, 'soumis_par' => $auteur->id, 'soumise_le' => now(), 'motif_rejet' => null])->save();
    }

    public function marquerConfirme(User $validateur): void
    {
        $this->forceFill(['statut' => StatutDistribution::Confirme, 'confirme_par' => $validateur->id, 'confirme_le' => now()])->save();
    }

    public function marquerRejete(string $motif): void
    {
        $this->forceFill(['statut' => StatutDistribution::Brouillon, 'motif_rejet' => $motif])->save();
    }

    /**
     * Confirmé et pas encore mis en paiement : tous les paramètres modifiables par avenant.
     *
     * @return list<string>|null
     */
    public function champsAvenantAutorises(): ?array
    {
        return $this->statut === StatutDistribution::Confirme ? null : [];
    }

    public function appliquerAvenant(array $valeurs): void
    {
        $this->fill(array_intersect_key($valeurs, array_flip(self::PARAMETRES)))->save();
    }

    public function creerVersion(User $auteur, ?string $motif = null): DistributionVersion
    {
        $numero = (int) $this->versions()->max('numero') + 1;

        $version = DistributionVersion::create([
            'distribution_id' => $this->id,
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
     * @return HasMany<DistributionVersion, $this>
     */
    public function versions(): HasMany
    {
        return $this->hasMany(DistributionVersion::class, 'distribution_id')->orderBy('numero');
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

    /**
     * @return BelongsTo<User, $this>
     */
    public function confirmateur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirme_par');
    }
}
