<?php

namespace App\Models;

use App\Enums\StatutTypeBoutique;
use App\Services\Backoffice\RegleGestionException;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Zone d'un site (bloc A, pôle boucherie-poissonnerie…), utilisée par les capacités prévues. Code jamais modifié ;
 * une zone ajoutée par le GESTIONNAIRE n'est utilisable qu'après validation (mêmes statuts que les types) ; une zone
 * désactivée est refusée à l'import ; une zone utilisée ou « par défaut » ne se supprime pas.
 */
#[Table(name: 'zones_sites')]
#[Fillable(['code', 'libelle', 'actif', 'par_defaut', 'statut'])]
class ZoneSite extends Model
{
    /** Code de zone : 1 à 5 lettres majuscules ou chiffres (A, MAG, BP). */
    public const FORMAT_CODE = '/^[A-Z0-9]{1,5}$/';

    protected function casts(): array
    {
        return ['actif' => 'boolean', 'par_defaut' => 'boolean', 'statut' => StatutTypeBoutique::class, 'valide_le' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::updating(function (ZoneSite $zone) {
            if ($zone->isDirty('code')) {
                throw new RegleGestionException(__('admin.zones.regles.code_fige'));
            }
        });
    }

    public function scopeUtilisables(Builder $requete): Builder
    {
        return $requete->where('statut', StatutTypeBoutique::Valide)->where('actif', true);
    }

    public function estUtilisable(): bool
    {
        return $this->statut === StatutTypeBoutique::Valide && $this->actif;
    }

    public function nombreCapacites(): int
    {
        return CapaciteSite::query()->where('zone', $this->code)->count();
    }
}
