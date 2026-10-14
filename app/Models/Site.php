<?php

namespace App\Models;

use App\Contracts\Parametrable;
use App\Enums\PhaseSite;
use App\Enums\StatutBoutique;
use App\Enums\StatutSite;
use App\Services\Backoffice\RegleGestionException;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * Site du marché (lieu physique) et ses boutiques, créé en amont des campagnes. Le GESTIONNAIRE DES SITES ET BOUTIQUES
 * saisit et soumet ; le VALIDATEUR confirme (workflow commun). Seuls les sites confirmés entrent dans une campagne.
 */
#[Fillable([
    'statut', 'code', 'nom', 'ville', 'arrondissement', 'region', 'commune', 'adresse', 'latitude', 'longitude',
    'maitre_ouvrage', 'maitre_oeuvre', 'phase', 'livraison_prevue', 'cout_total', 'part_occupants', 'source_complement',
    'reference_plans', 'version_plans', 'source_donnees', 'description', 'version',
    'cree_par', 'soumis_par', 'soumis_le', 'valide_par', 'valide_le', 'motif_rejet',
])]
class Site extends Model implements Parametrable
{
    public const PARAMETRES = [
        'code', 'nom', 'ville', 'arrondissement', 'region', 'adresse', 'latitude', 'longitude', 'maitre_ouvrage', 'maitre_oeuvre',
        'phase', 'livraison_prevue', 'cout_total', 'part_occupants', 'source_complement', 'reference_plans', 'version_plans',
        'source_donnees', 'description',
    ];

    /** Code court du site (RAA, SAR, A04) : premier élément du code de ses boutiques. */
    public const FORMAT_CODE = '/^[A-Z0-9]{3}$/';

    protected function casts(): array
    {
        return [
            'statut' => StatutSite::class,
            'phase' => PhaseSite::class,
            'livraison_prevue' => 'date',
            'latitude' => 'decimal:7',
            'longitude' => 'decimal:7',
            'cout_total' => 'integer',
            'part_occupants' => 'integer',
            'version' => 'integer',
            'soumis_le' => 'datetime',
            'valide_le' => 'datetime',
        ];
    }

    /** Ville (ou commune d'un site saisi avant la fiche complète). */
    public function localite(): ?string
    {
        return $this->ville ?? $this->commune;
    }

    /** Complément à financer : coût total − part attendue des occupants (calculé, jamais stocké). */
    public function complement(): ?int
    {
        return $this->cout_total !== null && $this->part_occupants !== null ? $this->cout_total - $this->part_occupants : null;
    }

    /** Part des occupants en % du coût total (calculée, jamais stockée). */
    public function partOccupantsPourcent(): ?float
    {
        return $this->cout_total && $this->part_occupants !== null ? $this->part_occupants / $this->cout_total * 100 : null;
    }

    protected static function booted(): void
    {
        static::updating(function (Site $site) {
            if ($site->isDirty('code') && $site->getOriginal('code') !== null && $site->codeFige()) {
                throw new RegleGestionException(__('admin.sites.regles.code_fige'));
            }
        });

        static::saved(function (Site $site) {
            if (! $site->wasChanged('code') || $site->code === null) {
                return;
            }
            $ancien = $site->getOriginal('code');
            if ($ancien !== null && $ancien !== $site->code) {
                // Changement de code (brouillon ou révision) : codes des boutiques recalculés, anciens codes conservés.
                HistoriqueCode::create(['objet_type' => HistoriqueCode::SITE, 'objet_id' => $site->id, 'ancien_code' => $ancien,
                    'nouveau_code' => $site->code, 'auteur_id' => auth()->id(), 'created_at' => now()]);
                $site->boutiques()->whereNotNull('code')->get()->each(fn (Boutique $b) => $b->recoder($site->code, auth()->user()));
            }
            // Boutiques complètes encore sans code (saisies avant les codes) : elles reçoivent le leur.
            $site->boutiques()->whereNull('code')->whereNotNull('niveau')->get()
                ->each(fn (Boutique $b) => $b->setRelation('site', $site)->save());
        });
    }

    /**
     * Le code d'un site soumis ou confirmé ne change jamais ; en brouillon ou en révision, il change tant qu'aucune
     * de ses boutiques n'est engagée dans une campagne ou attribuée (leurs codes servent aux tirages et aux SMS).
     */
    public function codeFige(): bool
    {
        $statut = $this->getOriginal('statut');
        $statut = $statut instanceof StatutSite ? $statut : StatutSite::tryFrom((string) $statut);

        return $statut !== StatutSite::Brouillon
            || $this->boutiques()->whereIn('statut', [StatutBoutique::EnCampagne, StatutBoutique::Attribuee])->exists();
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
        $snapshot = array_map(fn ($v) => match (true) {
            $v instanceof \BackedEnum => $v->label(),
            $v instanceof \DateTimeInterface => $v->format('m/Y'),
            default => $v,
        }, $this->only(self::PARAMETRES));
        $snapshot['capacites'] = $this->capacites()->with('type')->get()
            ->map(fn (CapaciteSite $c) => implode(' · ', array_filter([$c->niveau, $c->zone, $c->type->code ?? $c->type->libelle]))
                .' : '.($c->nombre_prevu ?? '—').' / '.($c->surfaceRetenue() !== null ? format_nombre($c->surfaceRetenue(), 2).' m²' : '—'))
            ->implode(' ; ');

        $boutiques = $this->boutiques()->where('statut', '!=', StatutBoutique::Retiree)->with('type')->get();
        $snapshot['boutiques'] = $boutiques->groupBy(fn (Boutique $b) => $b->type->libelle)
            ->map(fn ($groupe, $type) => $type.' : '.$groupe->count().' ('.$groupe->map->reference()->sort(SORT_NATURAL)->first().' à '.$groupe->map->reference()->sort(SORT_NATURAL)->last().')')
            ->sort()->implode(' ; ');
        $s = __('admin.sites');
        $snapshot['niveaux'] = $boutiques->groupBy(fn (Boutique $b) => $b->libelleNiveau() ?? $s['boutiques']['sans_niveau'])
            ->map(fn ($groupe, $niveau) => $niveau.' : '.$groupe->count())->sort()->implode(' ; ');
        $snapshot['etats_physiques'] = $boutiques->groupBy(fn (Boutique $b) => $b->etat_physique?->label() ?? $s['boutiques']['non_renseigne'])
            ->map(fn ($groupe, $etat) => $etat.' : '.$groupe->count())->sort()->implode(' ; ');
        // Fiche complète de chaque boutique, conservée dans l'historique ; le différentiel liste les boutiques modifiées.
        $snapshot['fiches'] = $boutiques->sortBy('id')->mapWithKeys(fn (Boutique $b) => [$b->reference() => implode(' · ', array_map(
            fn ($v) => $v ?? '—',
            [$b->niveau, $b->bloc, $b->surface, $b->type->libelle, $b->position?->label(), $b->coffret, $b->planche, $b->etat_physique?->label(), $b->observations],
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

    /**
     * @return HasMany<CapaciteSite, $this>
     */
    public function capacites(): HasMany
    {
        return $this->hasMany(CapaciteSite::class)->orderBy('niveau')->orderBy('zone')->orderBy('type_boutique_id');
    }

    /**
     * @return HasOne<GrilleTarifaire, $this>
     */
    public function grilleTarifaire(): HasOne
    {
        return $this->hasOne(GrilleTarifaire::class);
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
