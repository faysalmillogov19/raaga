<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Type de boutique (kiosque, petite boutique…) : catégorie commune à tous les sites. */
#[Table(name: 'types_boutiques')]
#[Fillable(['libelle', 'description', 'ordre'])]
class TypeBoutique extends Model
{
    /**
     * @return HasMany<Boutique, $this>
     */
    public function boutiques(): HasMany
    {
        return $this->hasMany(Boutique::class);
    }
}
