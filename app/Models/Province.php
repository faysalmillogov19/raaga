<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Province du Burkina Faso (découpage en vigueur depuis le 01/08/2025), rattachée à sa région, remplie par
 * ProvincesSeeder. Code officiel (« BF6201 ») et nom.
 */
#[Table(name: 'provinces')]
#[Fillable(['region_id', 'code', 'nom'])]
class Province extends Model
{
    /** @return BelongsTo<Region, $this> */
    public function region(): BelongsTo
    {
        return $this->belongsTo(Region::class);
    }

    /** @return HasMany<Commune, $this> */
    public function communes(): HasMany
    {
        return $this->hasMany(Commune::class);
    }
}
