<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * Greffe d'immatriculation au RCCM (réunion du 05/11/2026) : liste administrée par le SUPER_ADMIN ; « Autre
 * (préciser) » (code AUTRE) n'est jamais désactivé, la précision saisie reste visible de l'ADMIN.
 */
#[Fillable(['code', 'libelle', 'ordre', 'actif'])]
class Greffe extends Model
{
    public const AUTRE = 'AUTRE';

    protected function casts(): array
    {
        return ['actif' => 'boolean', 'ordre' => 'integer'];
    }

    /** Greffes proposés (actifs), « Autre » en dernier : id => libellé. @return Collection<int, string> */
    public static function proposes(): Collection
    {
        return self::query()->where('actif', true)->orderByRaw('code = ? asc', [self::AUTRE])->orderBy('ordre')->orderBy('libelle')->pluck('libelle', 'id');
    }

    public static function idAutre(): ?int
    {
        return self::query()->where('code', self::AUTRE)->value('id');
    }

    /** Greffe affiché pour un titulaire : le libellé de la liste, ou la précision d'« Autre ». */
    public static function libellePour(User $user): ?string
    {
        $greffe = $user->rccm_greffe_id ? self::query()->find($user->rccm_greffe_id) : null;

        return $greffe?->code === self::AUTRE ? $user->rccm_greffe_autre : $greffe?->libelle;
    }
}
