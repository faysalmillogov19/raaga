<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Guichet agréé : souscription et paiement en espèces auprès d'un agent. */
class Guichet extends Model
{
    protected $fillable = ['nom', 'commune_id', 'adresse', 'horaires', 'telephone', 'actif'];

    /** @return BelongsTo<Commune, $this> */
    public function commune(): BelongsTo
    {
        return $this->belongsTo(Commune::class);
    }

    protected function casts(): array
    {
        return ['actif' => 'boolean'];
    }

    public function scopeActifs(Builder $requete): Builder
    {
        return $requete->where('actif', true);
    }
}
