<?php

namespace App\Models;

use App\Enums\Role as RoleEnum;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Table de référence des rôles. Le code correspond à une valeur de App\Enums\Role.
 */
#[Fillable(['code', 'libelle', 'description'])]
class Role extends Model
{
    protected function casts(): array
    {
        return [
            'code' => RoleEnum::class,
        ];
    }

    /**
     * Ligne de la table correspondant au code donné.
     */
    public static function pour(RoleEnum $code): self
    {
        return static::query()->where('code', $code->value)->firstOrFail();
    }

    /**
     * Crée ou met à jour une ligne par valeur de l'enum (à appeler après l'ajout d'un rôle).
     */
    public static function synchroniser(): void
    {
        foreach (RoleEnum::cases() as $role) {
            static::query()->updateOrCreate(
                ['code' => $role->value],
                ['libelle' => $role->label(), 'description' => $role->description()],
            );
        }
    }

    public function label(): string
    {
        return $this->libelle;
    }

    /**
     * @return HasMany<User, $this>
     */
    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }
}
