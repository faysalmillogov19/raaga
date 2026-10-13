<?php

namespace App\Models;

use App\Models\Concerns\AppendOnly;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/** Ancien code d'un site ou d'une boutique, conservé lors d'un changement de code (ajout seul). */
#[Table(name: 'historique_codes', timestamps: false)]
#[Fillable(['objet_type', 'objet_id', 'ancien_code', 'nouveau_code', 'auteur_id', 'created_at'])]
class HistoriqueCode extends Model
{
    use AppendOnly;

    public const SITE = 'site';

    public const BOUTIQUE = 'boutique';

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }

    /** @param Builder<HistoriqueCode> $requete */
    public function scopeSites(Builder $requete): Builder
    {
        return $requete->where('objet_type', self::SITE);
    }

    /** @param Builder<HistoriqueCode> $requete */
    public function scopeBoutiques(Builder $requete): Builder
    {
        return $requete->where('objet_type', self::BOUTIQUE);
    }

    /** Site qui portait autrefois ce code (le plus récent), ou null. */
    public static function siteAnciennementCode(string $code): ?Site
    {
        $id = self::query()->sites()->where('ancien_code', $code)->latest('id')->value('objet_id');

        return $id ? Site::query()->find($id) : null;
    }
}
