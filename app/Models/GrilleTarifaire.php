<?php

namespace App\Models;

use App\Contracts\Parametrable;
use App\Enums\DecisionParametrage;
use App\Enums\StatutGrilleTarifaire;
use App\Enums\UniteLoyer;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * Grille tarifaire d'un site : une ligne par niveau et par type (loyer au m² et par mois, mois de caution, mois
 * d'avance). Saisie par le RÉGULATEUR, confirmée par le VALIDATEUR (workflow commun, versions figées). Seule la
 * dernière version confirmée sert aux campagnes, même pendant qu'une révision est en cours.
 */
#[Table(name: 'grilles_tarifaires')]
#[Fillable(['site_id', 'statut', 'version', 'cree_par', 'soumis_par', 'soumis_le', 'valide_par', 'valide_le', 'motif_rejet'])]
class GrilleTarifaire extends Model implements Parametrable
{
    protected function casts(): array
    {
        return [
            'statut' => StatutGrilleTarifaire::class,
            'version' => 'integer',
            'soumis_le' => 'datetime',
            'valide_le' => 'datetime',
        ];
    }

    /**
     * Montants d'une boutique, jamais saisis : loyer mensuel = surface × loyer au m² (arrondi au franc) pour un type
     * loué au m², sinon le loyer de la grille tel quel (par unité ou redevance) ; caution = loyer × mois de caution,
     * avance = loyer × mois d'avance.
     *
     * @return array{loyer_mensuel: int, caution: int, avance: int}
     */
    public static function montants(float $surface, int $loyerM2, int $moisCaution, int $moisAvance, UniteLoyer $unite = UniteLoyer::M2): array
    {
        $loyer = $unite->parSurface() ? (int) round($surface * $loyerM2) : $loyerM2;

        return ['loyer_mensuel' => $loyer, 'caution' => $loyer * $moisCaution, 'avance' => $loyer * $moisAvance];
    }

    /** Valeurs proposées au RÉGULATEUR pour une nouvelle ligne (configuration). @return array{mois_caution: int, mois_avance: ?int} */
    public static function valeursProposees(string $niveau, ?TypeBoutique $type): array
    {
        $config = config('raaga.grille_tarifaire');

        return [
            'mois_caution' => (int) $config['mois_caution'],
            'mois_avance' => $config['mois_avance_par_type'][$type?->code] ?? $config['mois_avance_par_niveau'][$niveau] ?? null,
        ];
    }

    /** Clé d'une ligne dans les versions : « R1|3 » (niveau | type). */
    public static function cle(string $niveau, int $typeId): string
    {
        return $niveau.'|'.$typeId;
    }

    /**
     * Lignes de la dernière version confirmée : [version, [clé => [loyer_m2, mois_caution, mois_avance]]], ou null.
     *
     * @return array{version: int, lignes: array<string, array{loyer_m2: int, mois_caution: int, mois_avance: int}>}|null
     */
    public function confirmee(): ?array
    {
        $numero = ParametrageDecision::query()->where('objet_type', $this->typeParametrage())->where('objet_id', $this->id)
            ->where('decision', DecisionParametrage::Confirme)->max('version');
        $version = $numero ? $this->versions()->where('numero', $numero)->first() : null;

        return $version ? ['version' => $version->numero, 'lignes' => $version->parametres['lignes'] ?? []] : null;
    }

    public function typeParametrage(): string
    {
        return 'grille_tarifaire';
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

    public function marquerRejete(string $motif): void
    {
        $this->forceFill(['statut' => StatutGrilleTarifaire::Brouillon, 'motif_rejet' => $motif])->save();
    }

    /** Pas d'avenant : une grille confirmée se remet en révision, puis se soumet de nouveau. */
    public function champsAvenantAutorises(): ?array
    {
        return [];
    }

    public function appliquerAvenant(array $valeurs): void {}

    public function creerVersion(User $auteur, ?string $motif = null): GrilleTarifaireVersion
    {
        $numero = (int) $this->versions()->max('numero') + 1;

        $version = GrilleTarifaireVersion::create([
            'grille_tarifaire_id' => $this->id, 'numero' => $numero, 'parametres' => $this->snapshotParametres(),
            'auteur_id' => $auteur->id, 'motif' => $motif, 'created_at' => now(),
        ]);
        $this->forceFill(['version' => $numero])->save();

        return $version;
    }

    /**
     * « grille » : résumé lisible (différentiel) ; « lignes » : valeurs exactes, lues pour les campagnes.
     *
     * @return array<string, mixed>
     */
    public function snapshotParametres(): array
    {
        $lignes = $this->lignes()->with('type')->get();

        return [
            'grille' => $lignes->map(fn (GrilleTarifaireLigne $l) => __('admin.grilles.resume_ligne', [
                'niveau' => $l->libelleNiveau(), 'type' => $l->type->libelle, 'loyer' => format_fcfa($l->loyer_m2),
                'caution' => $l->mois_caution, 'avance' => $l->mois_avance,
            ]))->implode(' ; '),
            'lignes' => $lignes->mapWithKeys(fn (GrilleTarifaireLigne $l) => [self::cle($l->niveau, $l->type_boutique_id) => [
                'loyer_m2' => $l->loyer_m2, 'mois_caution' => $l->mois_caution, 'mois_avance' => $l->mois_avance,
            ]])->all(),
        ];
    }

    /**
     * @return BelongsTo<Site, $this>
     */
    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    /**
     * @return HasMany<GrilleTarifaireLigne, $this>
     */
    public function lignes(): HasMany
    {
        return $this->hasMany(GrilleTarifaireLigne::class)->orderBy('niveau')->orderBy('type_boutique_id');
    }

    /**
     * @return HasMany<GrilleTarifaireVersion, $this>
     */
    public function versions(): HasMany
    {
        return $this->hasMany(GrilleTarifaireVersion::class)->orderBy('numero');
    }

    public function avenants(): MorphMany
    {
        return $this->morphMany(Avenant::class, 'avenantable')->latest('id');
    }
}
