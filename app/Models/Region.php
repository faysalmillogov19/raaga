<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;

/**
 * Région du Burkina Faso (découpage en vigueur depuis le 01/08/2025), remplie par RegionsSeeder. Code officiel
 * (« BF62 »), nom et chef-lieu.
 */
#[Table(name: 'regions')]
#[Fillable(['code', 'nom', 'chef_lieu'])]
class Region extends Model
{
    /** @return HasMany<Province, $this> */
    public function provinces(): HasMany
    {
        return $this->hasMany(Province::class);
    }

    /** @return HasManyThrough<Commune, Province, $this> */
    public function communes(): HasManyThrough
    {
        return $this->hasManyThrough(Commune::class, Province::class);
    }
}
