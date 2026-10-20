<?php

namespace App\Models;

use App\Contracts\Parametrable;
use App\Enums\DecisionParametrage;
use App\Enums\StatutGrilleTarifaire;
use App\Support\MemoRequete;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * Paramètres des paiements (une seule fiche) : quatre délais, chacun en valeur + durée (SECONDE, MINUTE, HEURE, JOUR),
 * avec sa valeur en secondes calculée et stockée. Délai de paiement de la caution (72 heures par défaut), délai de
 * complément d'un paiement incomplet (48 heures), délai pour payer l'avance à compter du passage de la boutique en
 * « réservée » (30 jours), délai de correction d'une identité invalide, commun aux boutiques et aux actions
 * (48 heures). Saisis par le RÉGULATEUR, confirmés par le VALIDATEUR (workflow commun, versions figées) ; valeurs
 * initiales posées par ParametresPaiementsSeeder, confirmées d'office ; à défaut, les valeurs par défaut s'appliquent.
 */
#[Table(name: 'parametres_paiements')]
#[Fillable([
    'delai_caution_valeur', 'delai_caution_duree_id', 'delai_caution_secondes',
    'delai_complement_valeur', 'delai_complement_duree_id', 'delai_complement_secondes',
    'delai_avance_valeur', 'delai_avance_duree_id', 'delai_avance_secondes',
    'delai_kyc_valeur', 'delai_kyc_duree_id', 'delai_kyc_secondes',
    'statut', 'version', 'soumis_par', 'soumis_le', 'valide_par', 'valide_le', 'motif_rejet',
])]
class ParametresPaiements extends Model implements Parametrable
{
    /** Délais : clé => [valeur, durée] par défaut. */
    public const DELAIS = ['caution' => [72, 'HEURE'], 'complement' => [48, 'HEURE'], 'avance' => [30, 'JOUR'], 'kyc' => [48, 'HEURE']];

    /** Plafond d'un délai : un an. */
    public const SECONDES_MAX = 31_536_000;

    public const PARAMETRES = [
        'delai_caution_valeur', 'delai_caution_duree_id', 'delai_caution_secondes',
        'delai_complement_valeur', 'delai_complement_duree_id', 'delai_complement_secondes',
        'delai_avance_valeur', 'delai_avance_duree_id', 'delai_avance_secondes',
        'delai_kyc_valeur', 'delai_kyc_duree_id', 'delai_kyc_secondes',
    ];

    protected function casts(): array
    {
        return [
            'statut' => StatutGrilleTarifaire::class,
            ...collect(self::PARAMETRES)->mapWithKeys(fn (string $c) => [$c => 'integer'])->all(),
            'version' => 'integer',
            'soumis_le' => 'datetime',
            'valide_le' => 'datetime',
        ];
    }

    /** La fiche unique (créée vide, en brouillon, à la première saisie). */
    public static function fiche(): self
    {
        return static::query()->orderBy('id')->first() ?? new self(['statut' => StatutGrilleTarifaire::Brouillon]);
    }

    /**
     * Délais de la dernière version confirmée, en secondes (lus une fois par page). Une ancienne version qui ne
     * connaissait que le délai de complément en heures reste lue.
     *
     * @return array<string, int>
     */
    public static function confirmes(): array
    {
        return MemoRequete::retenir('parametres_paiements.confirmes', function () {
            $fiche = static::query()->orderBy('id')->first();
            $numero = $fiche ? ParametrageDecision::query()->where('objet_type', 'delais')->where('objet_id', $fiche->id)
                ->where('decision', DecisionParametrage::Confirme)->latest('id')->value('version') : null;
            $p = $numero ? $fiche->versions()->where('numero', $numero)->value('parametres') : null;
            $p = (is_string($p) ? json_decode($p, true) : $p) ?? [];
            if (isset($p['delai_complement_heures'])) {
                $p['delai_complement_secondes'] = (int) $p['delai_complement_heures'] * 3_600;
            }

            return collect(array_keys(self::DELAIS))->mapWithKeys(fn (string $d) => [$d => (int) ($p['delai_'.$d.'_secondes'] ?? 0)])->filter()->all();
        });
    }

    /** Délai en vigueur, en secondes : valeur confirmée, sinon la valeur par défaut. */
    public static function delaiSecondes(string $delai): int
    {
        [$valeur, $duree] = self::DELAIS[$delai];

        return self::confirmes()[$delai] ?? $valeur * Duree::DUREES[$duree][2];
    }

    /**
     * Délai en vigueur, lisible (« 72 heures », « 30 jours ») : valeur et durée confirmées, sinon par défaut.
     */
    public static function libelleEnVigueur(string $delai): string
    {
        $secondes = self::delaiSecondes($delai);
        [$valeur, $duree] = self::DELAIS[$delai];
        if (isset(self::confirmes()[$delai])) {
            // Plus grande durée qui divise exactement le délai confirmé.
            $code = collect(Duree::DUREES)->sortByDesc(fn ($d) => $d[2])->keys()->first(fn (string $c) => $secondes % Duree::DUREES[$c][2] === 0);
            [$valeur, $duree] = [intdiv($secondes, Duree::DUREES[$code][2]), $code];
        }

        return Duree::formater($valeur, $duree);
    }

    /** Délai saisi sur la fiche (brouillon ou soumis), lisible ; null s'il n'est pas saisi. */
    public function libelleSaisi(string $delai): ?string
    {
        $duree = Duree::parId($this->{'delai_'.$delai.'_duree_id'});

        return $duree && $this->{'delai_'.$delai.'_valeur'} ? $duree->libelle($this->{'delai_'.$delai.'_valeur'}) : null;
    }

    public function typeParametrage(): string
    {
        return 'delais';
    }

    public function estModifiable(): bool
    {
        return $this->statut === StatutGrilleTarifaire::Brouillon;
    }

    public function estSoumis(): bool
    {
        return $this->statut === StatutGrilleTarifaire::Soumis;
    }

    public function marquerSoumis(User $auteur): void
    {
        $this->forceFill(['statut' => StatutGrilleTarifaire::Soumis, 'soumis_par' => $auteur->id, 'soumis_le' => now(), 'motif_rejet' => null])->save();
    }

    public function marquerConfirme(User $validateur): void
    {
        $this->forceFill(['statut' => StatutGrilleTarifaire::Confirme, 'valide_par' => $validateur->id, 'valide_le' => now()])->save();
    }

    /** Retiré de la validation par son auteur, avant toute décision : retour au brouillon. */
    public function marquerRetire(): void
    {
        $this->forceFill(['statut' => StatutGrilleTarifaire::Brouillon])->save();
    }

    public function marquerRejete(string $motif): void
    {
        $this->forceFill(['statut' => StatutGrilleTarifaire::Brouillon, 'motif_rejet' => $motif])->save();
    }

    /** Pas d'avenant : des paramètres confirmés se remettent en révision, puis se soumettent de nouveau. */
    public function champsAvenantAutorises(): ?array
    {
        return [];
    }

    public function appliquerAvenant(array $valeurs): void {}

    public function creerVersion(User $auteur, ?string $motif = null): ParametresPaiementsVersion
    {
        $numero = (int) $this->versions()->max('numero') + 1;
        $version = ParametresPaiementsVersion::create([
            'parametres_paiements_id' => $this->id, 'numero' => $numero, 'parametres' => $this->snapshotParametres(),
            'auteur_id' => $auteur->id, 'motif' => $motif, 'created_at' => now(),
        ]);
        $this->forceFill(['version' => $numero])->save();

        return $version;
    }

    /**
     * Version figée : pour chaque délai, la valeur, le code de la durée et la valeur en secondes.
     *
     * @return array<string, mixed>
     */
    public function snapshotParametres(): array
    {
        return collect(array_keys(self::DELAIS))->flatMap(fn (string $d) => [
            'delai_'.$d.'_valeur' => $this->{'delai_'.$d.'_valeur'},
            'delai_'.$d.'_duree' => Duree::parId($this->{'delai_'.$d.'_duree_id'})?->code,
            'delai_'.$d.'_secondes' => $this->{'delai_'.$d.'_secondes'},
        ])->all();
    }

    /**
     * @return HasMany<ParametresPaiementsVersion, $this>
     */
    public function versions(): HasMany
    {
        return $this->hasMany(ParametresPaiementsVersion::class)->orderBy('numero');
    }

    public function avenants(): MorphMany
    {
        return $this->morphMany(Avenant::class, 'avenantable')->latest('id');
    }
}
