<?php

namespace App\Models;

use App\Enums\StatutSite;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

/**
 * Import confirmé (sites et capacités, ou boutiques) et sa validation par le VALIDATEUR. Statut choisi à l'import :
 * « Brouillon » (sites laissés en brouillon) ou « Soumettre au VALIDATEUR ». Un site encore soumis par cet import
 * (même date de soumission) attend la décision sur l'import ; validé ou rejeté séparément, il en est retiré.
 */
#[Table(name: 'imports_validation')]
#[Fillable(['entite', 'fichier', 'chemin', 'empreinte', 'auteur_id', 'statut_apres', 'contenu', 'resume', 'avertissements'])]
class ImportValidation extends Model
{
    public const BROUILLON = 'BROUILLON';

    public const SOUMETTRE = 'SOUMETTRE';

    public const VALIDE = 'VALIDE';

    public const REJETE = 'REJETE';

    /** Statut de validation affiché (liste des imports, « À valider ») : Brouillon, Soumis, Validé, Rejeté, Partiellement validé. */
    public const STATUTS = ['BROUILLON', 'SOUMIS', 'VALIDE', 'REJETE', 'PARTIEL'];

    protected function casts(): array
    {
        return ['contenu' => 'array', 'resume' => 'array', 'avertissements' => 'array', 'decide_le' => 'datetime'];
    }

    public function auteur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'auteur_id');
    }

    public function decideur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decide_par');
    }

    /** @return HasMany<ImportValidationSite, $this> */
    public function sites(): HasMany
    {
        return $this->hasMany(ImportValidationSite::class, 'import_id');
    }

    /** Imports qui attendent une décision : soumis, sans décision, avec au moins un site encore soumis par l'import. */
    public function scopeEnAttente(Builder $q): Builder
    {
        return $q->where('statut_apres', self::SOUMETTRE)->whereNull('decision')
            ->whereHas('sites', fn ($s) => $s->whereNull('resultat')->whereNotNull('soumis_le')
                ->whereHas('site', fn ($site) => $site->where('statut', StatutSite::Soumis)->whereColumn('sites.soumis_le', 'import_validation_sites.soumis_le')));
    }

    /** @return Collection<int, ImportValidationSite> lignes dont le site attend encore la décision sur l'import */
    public function sitesEnAttente()
    {
        return $this->sites->filter(fn (ImportValidationSite $l) => $l->enAttente())->values();
    }

    public function statutValidation(): string
    {
        $soumis = $this->sites->filter(fn (ImportValidationSite $l) => $l->soumis_le !== null);
        if ($this->statut_apres === self::BROUILLON || $soumis->isEmpty()) {
            return 'BROUILLON';
        }
        if ($this->decision === self::REJETE) {
            return 'REJETE';
        }
        if ($this->decision === null && $this->sitesEnAttente()->isNotEmpty()) {
            return 'SOUMIS';
        }

        return $soumis->every(fn (ImportValidationSite $l) => $l->site->statut === StatutSite::Confirme) && $this->sites->count() === $soumis->count()
            ? 'VALIDE' : 'PARTIEL';
    }
}
