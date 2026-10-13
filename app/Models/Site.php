<?php

namespace App\Models;

use App\Contracts\Parametrable;
use App\Enums\StatutBoutique;
use App\Enums\StatutSite;
use App\Services\Backoffice\RegleGestionException;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * Site du marché (lieu physique) et ses boutiques, créé en amont des campagnes. Le GESTIONNAIRE DES SITES ET BOUTIQUES
 * saisit et soumet ; le VALIDATEUR confirme (workflow commun). Seuls les sites confirmés entrent dans une campagne.
 */
#[Fillable([
    'statut', 'code', 'nom', 'region', 'commune', 'adresse', 'description', 'version',
    'cree_par', 'soumis_par', 'soumis_le', 'valide_par', 'valide_le', 'motif_rejet',
])]
class Site extends Model implements Parametrable
{
    public const PARAMETRES = ['code', 'nom', 'region', 'commune', 'adresse', 'description'];

    /** Code court du site (RAA, SAR, A04) : premier élément du code de ses boutiques. */
    public const FORMAT_CODE = '/^[A-Z0-9]{3}$/';

    protected function casts(): array
    {
        return [
            'statut' => StatutSite::class,
            'version' => 'integer',
            'soumis_le' => 'datetime',
            'valide_le' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (Site $site) {
            if ($site->isDirty('code') && $site->getOriginal('code') !== null && $site->codeFige()) {
                throw new RegleGestionException(__('admin.sites.regles.code_fige'));
            }
        });

        // Code du site renseigné après coup (site saisi avant les codes) : les boutiques complètes reçoivent leur code.
        static::saved(function (Site $site) {
            if ($site->wasChanged('code') && $site->code !== null) {
                $site->boutiques()->whereNull('code')->whereNotNull('niveau')->get()
                    ->each(fn (Boutique $b) => $b->setRelation('site', $site)->save());
            }
        });
    }

    /** Le code ne change plus après la première confirmation ni dès qu'une boutique porte un code calculé avec lui. */
    public function codeFige(): bool
    {
        return $this->getOriginal('valide_le') !== null || $this->boutiques()->withTrashed()->whereNotNull('code')->exists();
    }

    /**
     * Boutiques sans niveau (saisies avant les codes) : le site ne peut pas être soumis tant qu'il en reste.
     *
     * @return HasMany<Boutique, $this>
     */
    public function boutiquesSansNiveau(): HasMany
    {
        return $this->boutiques()->whereNull('niveau')->where('statut', '!=', StatutBoutique::Retiree);
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

        $boutiques = $this->boutiques()->where('statut', '!=', StatutBoutique::Retiree)->with('type')->get();
        $snapshot['boutiques'] = $boutiques->groupBy(fn (Boutique $b) => $b->type->libelle)
            ->map(fn ($groupe, $type) => $type.' : '.$groupe->count().' ('.$groupe->map->reference()->sort(SORT_NATURAL)->first().' à '.$groupe->map->reference()->sort(SORT_NATURAL)->last().')')
            ->sort()->implode(' ; ');
        $s = __('admin.sites');
        $snapshot['niveaux'] = $boutiques->groupBy(fn (Boutique $b) => $b->niveau?->label() ?? $s['boutiques']['sans_niveau'])
            ->map(fn ($groupe, $niveau) => $niveau.' : '.$groupe->count())->sort()->implode(' ; ');
        $snapshot['etats_physiques'] = $boutiques->groupBy(fn (Boutique $b) => $b->etat_physique?->label() ?? $s['boutiques']['non_renseigne'])
            ->map(fn ($groupe, $etat) => $etat.' : '.$groupe->count())->sort()->implode(' ; ');
        // Fiche complète de chaque boutique, conservée dans l'historique ; le différentiel liste les boutiques modifiées.
        $snapshot['fiches'] = $boutiques->sortBy('id')->mapWithKeys(fn (Boutique $b) => [$b->reference() => implode(' · ', array_map(
            fn ($v) => $v ?? '—',
            [$b->niveau?->value, $b->bloc, $b->surface, $b->type->libelle, $b->position?->label(), $b->coffret, $b->planche, $b->etat_physique?->label()],
        ))])->all();

        return $snapshot;
    }

    /**
     * @return HasMany<Boutique, $this>
     */
    public function boutiques(): HasMany
    {
        return $this->hasMany(Boutique::class)->orderByRaw('code IS NULL, code, LENGTH(numero), numero');
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
