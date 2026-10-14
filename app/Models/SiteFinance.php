<?php

namespace App\Models;

use App\Contracts\Parametrable;
use App\Enums\StatutGrilleTarifaire;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * Données financières d'un site (coût total, part attendue des occupants, source du complément), séparées de sa fiche
 * physique : saisies par le RÉGULATEUR, confirmées par le VALIDATEUR (workflow commun, versions figées), jamais
 * visibles du GESTIONNAIRE DES SITES ET BOUTIQUES. Complément et part en % sont calculés, jamais stockés.
 */
#[Table(name: 'site_finances')]
#[Fillable(['site_id', 'cout_total', 'part_occupants', 'source_complement', 'statut', 'version', 'soumis_par', 'soumis_le', 'valide_par', 'valide_le', 'motif_rejet'])]
class SiteFinance extends Model implements Parametrable
{
    public const PARAMETRES = ['cout_total', 'part_occupants', 'source_complement'];

    protected function casts(): array
    {
        return [
            'statut' => StatutGrilleTarifaire::class,
            'cout_total' => 'integer',
            'part_occupants' => 'integer',
            'version' => 'integer',
            'soumis_le' => 'datetime',
            'valide_le' => 'datetime',
        ];
    }

    /** Complément à financer : coût total − part attendue des occupants. */
    public function complement(): ?int
    {
        return $this->cout_total !== null && $this->part_occupants !== null ? $this->cout_total - $this->part_occupants : null;
    }

    /** Part des occupants en % du coût total. */
    public function partOccupantsPourcent(): ?float
    {
        return $this->cout_total && $this->part_occupants !== null ? $this->part_occupants / $this->cout_total * 100 : null;
    }

    public function typeParametrage(): string
    {
        return 'site_finance';
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

    /** Pas d'avenant : des données confirmées se remettent en révision, puis se soumettent de nouveau. */
    public function champsAvenantAutorises(): ?array
    {
        return [];
    }

    public function appliquerAvenant(array $valeurs): void {}

    public function creerVersion(User $auteur, ?string $motif = null): SiteFinanceVersion
    {
        $numero = (int) $this->versions()->max('numero') + 1;
        $version = SiteFinanceVersion::create([
            'site_finance_id' => $this->id, 'numero' => $numero, 'parametres' => $this->snapshotParametres(),
            'auteur_id' => $auteur->id, 'motif' => $motif, 'created_at' => now(),
        ]);
        $this->forceFill(['version' => $numero])->save();

        return $version;
    }

    /** @return array<string, mixed> */
    public function snapshotParametres(): array
    {
        return $this->only(self::PARAMETRES);
    }

    /**
     * @return BelongsTo<Site, $this>
     */
    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    /**
     * @return HasMany<SiteFinanceVersion, $this>
     */
    public function versions(): HasMany
    {
        return $this->hasMany(SiteFinanceVersion::class)->orderBy('numero');
    }

    public function avenants(): MorphMany
    {
        return $this->morphMany(Avenant::class, 'avenantable')->latest('id');
    }
}
