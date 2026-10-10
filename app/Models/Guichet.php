<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/** Guichet agréé : souscription et paiement en espèces auprès d'un agent. */
class Guichet extends Model
{
    protected $fillable = ['nom', 'region', 'commune', 'adresse', 'horaires', 'telephone', 'actif'];

    protected function casts(): array
    {
        return ['actif' => 'boolean'];
    }

    public function scopeActifs(Builder $requete): Builder
    {
        return $requete->where('actif', true);
    }
}
