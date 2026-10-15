<?php

namespace App\Models;

use App\Enums\StatutTypeBoutique;
use App\Models\Concerns\ModificationDemandee;
use App\Services\Backoffice\RegleGestionException;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Type de local (boutique, magasin, étal…) commun à tous les sites. Le code ne change jamais et n'est jamais
 * réutilisé. Un type ajouté par le GESTIONNAIRE DES SITES ET BOUTIQUES, ou un changement d'« attribuable par
 * tirage », n'est effectif qu'après validation par le VALIDATEUR. Un type désactivé disparaît des saisies, des
 * imports et des nouvelles campagnes ; les boutiques existantes le gardent. Chaque changement crée une version.
 */
#[Table(name: 'types_boutiques')]
#[Fillable(['code', 'libelle', 'description', 'attribuable', 'actif', 'par_defaut', 'statut', 'ordre'])]
class TypeBoutique extends Model
{
    use ModificationDemandee;

    /** Code du type : 2 à 5 lettres majuscules (BOUT, MAG, ETAL, CF). */
    public const FORMAT_CODE = '/^[A-Z]{2,5}$/';

    /** Libellé, description et ordre : modifiables à tout moment, sans validation. */
    public const CHAMPS_LIBRES = ['libelle', 'description', 'ordre'];

    protected function casts(): array
    {
        return [
            'statut' => StatutTypeBoutique::class,
            // « attribuable » faux : type jamais proposé au tirage au sort (chambre froide mutualisée).
            'attribuable' => 'boolean',
            'attribuable_demande' => 'boolean',
            'actif' => 'boolean',
            'par_defaut' => 'boolean',
            'ordre' => 'integer',
            'version' => 'integer',
            'modification_demandee' => 'array',
            'demande_le' => 'datetime',
            'valide_le' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (TypeBoutique $type) {
            if ($type->isDirty('code') && $type->getOriginal('code') !== null) {
                throw new RegleGestionException(__('admin.types.regles.code_fige'));
            }
        });
    }

    /** Types proposés dans les saisies, les imports et les nouvelles campagnes : validés et actifs. */
    public function scopeUtilisables(Builder $requete): Builder
    {
        return $requete->where('statut', StatutTypeBoutique::Valide)->where('actif', true);
    }

    public function estUtilisable(): bool
    {
        return $this->statut === StatutTypeBoutique::Valide && $this->actif;
    }

    /** Motif de refus d'un type non utilisable (désactivé, en attente ou rejeté), ou null. */
    public function motifInutilisable(): ?string
    {
        return match (true) {
            $this->statut !== StatutTypeBoutique::Valide => __('admin.types.regles.non_valide', ['code' => $this->code ?? $this->libelle]),
            ! $this->actif => __('admin.types.regles.desactive', ['code' => $this->code ?? $this->libelle]),
            default => null,
        };
    }

    /** Utilisé par une boutique (même supprimée), une capacité, une grille tarifaire ou une campagne. */
    public function estUtilise(): bool
    {
        return $this->boutiques()->withTrashed()->exists()
            || CapaciteSite::query()->where('type_boutique_id', $this->id)->exists()
            || GrilleTarifaireLigne::query()->where('type_boutique_id', $this->id)->exists()
            || GrilleTarifaireVersion::query()->where('parametres', 'like', '%|'.$this->id.'"%')->exists()
            || CategorieBoutique::query()->where('type_boutique_id', $this->id)->exists();
    }

    public function creerVersion(?User $auteur, string $motif): TypeBoutiqueVersion
    {
        $numero = (int) TypeBoutiqueVersion::query()->where('type_boutique_id', $this->id)->max('numero') + 1;
        $version = TypeBoutiqueVersion::create([
            'type_boutique_id' => $this->id, 'numero' => $numero, 'auteur_id' => $auteur?->id, 'motif' => $motif, 'created_at' => now(),
            'parametres' => [
                'code' => $this->code, 'libelle' => $this->libelle, 'description' => $this->description,
                'attribuable' => $this->attribuable, 'actif' => $this->actif, 'ordre' => $this->ordre, 'statut' => $this->statut?->value,
            ],
        ]);
        $this->forceFill(['version' => $numero])->saveQuietly();

        return $version;
    }

    /**
     * @return HasMany<Boutique, $this>
     */
    public function boutiques(): HasMany
    {
        return $this->hasMany(Boutique::class);
    }

    /**
     * @return HasMany<TypeBoutiqueVersion, $this>
     */
    public function versions(): HasMany
    {
        return $this->hasMany(TypeBoutiqueVersion::class)->orderByDesc('numero');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function demandeur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'demande_par');
    }
}
