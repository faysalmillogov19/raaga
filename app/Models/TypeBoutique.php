<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Type de boutique (boutique, magasin, étal…) avec son code : catégorie commune à tous les sites. */
#[Table(name: 'types_boutiques')]
#[Fillable(['code', 'libelle', 'description', 'ordre'])]
class TypeBoutique extends Model
{
    /** Code du type (BOUT, MAG, ETAL, CF). */
    public const FORMAT_CODE = '/^[A-Z0-9]{2,6}$/';

    /**
     * @return HasMany<Boutique, $this>
     */
    public function boutiques(): HasMany
    {
        return $this->hasMany(Boutique::class);
    }
}
