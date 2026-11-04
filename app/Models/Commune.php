<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Commune du Burkina Faso (découpage en vigueur depuis le 01/08/2025), rattachée à sa province, remplie par
 * CommunesSeeder. Code officiel (« BF620101 ») et nom. Seul échelon retenu pour les comptes, les sites et les guichets.
 */
#[Table(name: 'communes')]
#[Fillable(['province_id', 'code', 'nom'])]
class Commune extends Model
{
    /** @return BelongsTo<Province, $this> */
    public function province(): BelongsTo
    {
        return $this->belongsTo(Province::class);
    }

    /** Nom suivi de sa région (« Bobo-Dioulasso (Guiriko) »). */
    public function libelleComplet(): string
    {
        return $this->nom.' ('.$this->province->region->nom.')';
    }

    /**
     * Communes à proposer dans les formulaires, groupées par province (« province (région) » => [id => nom de la commune]),
     * provinces et communes dans l'ordre alphabétique.
     *
     * @return array<string, array<int, string>>
     */
    public static function parProvince(): array
    {
        return static::query()->with('province.region')->get()
            ->sortBy(fn (Commune $c) => Str::ascii($c->province->nom.' '.$c->nom))
            ->groupBy(fn (Commune $c) => $c->province->nom.' ('.$c->province->region->nom.')')
            ->map(fn (Collection $communes) => $communes->pluck('nom', 'id')->all())
            ->all();
    }
}
