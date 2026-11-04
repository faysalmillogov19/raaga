<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Famille de secteurs d'activité (Alimentation, Habillement et beauté, Maison et équipement, Autres commerces, Services),
 * remplie par FamillesSecteursSeeder. Sert au regroupement des secteurs dans les listes et les statistiques.
 */
#[Table(name: 'familles_secteurs')]
#[Fillable(['code', 'libelle', 'ordre', 'actif'])]
class FamilleSecteur extends Model
{
    protected function casts(): array
    {
        return ['actif' => 'boolean'];
    }

    /** @return HasMany<SecteurActivite, $this> */
    public function secteurs(): HasMany
    {
        return $this->hasMany(SecteurActivite::class);
    }
}
