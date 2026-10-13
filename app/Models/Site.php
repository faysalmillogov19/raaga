<?php

namespace App\Models;

use App\Contracts\Parametrable;
use App\Enums\StatutBoutique;
use App\Enums\StatutSite;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * Site du marché (lieu physique) et ses boutiques, créé en amont des campagnes. Le GESTIONNAIRE DES SITES
 * saisit et soumet ; le VALIDATEUR confirme (workflow commun). Seuls les sites confirmés entrent dans une campagne.
 */
#[Fillable([
    'statut', 'nom', 'region', 'commune', 'adresse', 'description', 'version',
    'cree_par', 'soumis_par', 'soumis_le', 'valide_par', 'valide_le', 'motif_rejet',
])]
class Site extends Model implements Parametrable
{
    public const PARAMETRES = ['nom', 'region', 'commune', 'adresse', 'description'];

    protected function casts(): array
    {
        return [
            'statut' => StatutSite::class,
            'version' => 'integer',
            'soumis_le' => 'datetime',
            'valide_le' => 'datetime',
        ];
    }

    public function scopeConfirmes(Builder $requete): Builder
    {
        return $requete->where('statut', StatutSite::Confirme);
    }

    public function typeParametrage(): string
    {
        return 'site';
    }

    public function estModifiable(): bool
    {
        return $this->statut === StatutSite::Brouillon;
    }

    public function estSoumis(): bool
    {
        return $this->statut === StatutSite::Soumis;
    }

    public function marquerSoumis(User $auteur): void
    {
        $this->forceFill(['statut' => StatutSite::Soumis, 'soumis_par' => $auteur->id, 'soumis_le' => now(), 'motif_rejet' => null])->save();
    }

    public function marquerConfirme(User $validateur): void
    {
        $this->forceFill(['statut' => StatutSite::Confirme, 'valide_par' => $validateur->id, 'valide_le' => now()])->save();
    }

    public function marquerRejete(string $motif): void
    {
        $this->forceFill(['statut' => StatutSite::Brouillon, 'motif_rejet' => $motif])->save();
    }

    /** Pas d'avenant : un site confirmé se remet en révision (brouillon), puis se soumet de nouveau. */
    public function champsAvenantAutorises(): ?array
    {
        return [];
    }

    public function appliquerAvenant(array $valeurs): void {}

    public function creerVersion(User $auteur, ?string $motif = null): SiteVersion
    {
        $numero = (int) $this->versions()->max('numero') + 1;

        $version = SiteVersion::create([
            'site_id' => $this->id, 'numero' => $numero, 'parametres' => $this->snapshotParametres(),
            'auteur_id' => $auteur->id, 'motif' => $motif, 'created_at' => now(),
        ]);
        $this->forceFill(['version' => $numero])->save();

        return $version;
    }

    /**
     * Champs du site et résumé lisible des boutiques par type (pour l'historique et le différentiel).
     *
     * @return array<string, mixed>
     */
    public function snapshotParametres(): array
    {
        $snapshot = array_map(fn ($v) => $v, $this->only(self::PARAMETRES));

        $snapshot['boutiques'] = $this->boutiques()->where('statut', '!=', StatutBoutique::Retiree)->with('type')->get()
            ->groupBy(fn (Boutique $b) => $b->type->libelle)
            ->map(fn ($groupe, $type) => $type.' : '.$groupe->count().' ('.$groupe->pluck('numero')->sort(SORT_NATURAL)->first().' à '.$groupe->pluck('numero')->sort(SORT_NATURAL)->last().')')
            ->sort()->implode(' ; ');

        return $snapshot;
    }

    /**
     * @return HasMany<Boutique, $this>
     */
    public function boutiques(): HasMany
    {
        return $this->hasMany(Boutique::class)->orderByRaw('LENGTH(numero), numero');
    }

    /**
     * @return HasMany<SiteVersion, $this>
     */
    public function versions(): HasMany
    {
        return $this->hasMany(SiteVersion::class)->orderBy('numero');
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
