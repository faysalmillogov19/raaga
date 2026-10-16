<?php

namespace App\Models;

use App\Support\MemoRequete;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

/**
 * Durée d'un délai (SECONDE, MINUTE, HEURE, JOUR) et sa valeur en secondes. Remplie par le seeder, confirmée d'office,
 * sans circuit de validation et non modifiable à l'écran. Un délai s'enregistre en valeur + durée, avec sa valeur
 * convertie en secondes.
 */
#[Table(name: 'durees')]
#[Fillable(['code', 'libelle', 'libelle_pluriel', 'secondes', 'ordre'])]
class Duree extends Model
{
    /** Durées de référence : code => [libellé, pluriel, secondes]. Aucune autre. */
    public const DUREES = [
        'SECONDE' => ['seconde', 'secondes', 1],
        'MINUTE' => ['minute', 'minutes', 60],
        'HEURE' => ['heure', 'heures', 3_600],
        'JOUR' => ['jour', 'jours', 86_400],
    ];

    protected function casts(): array
    {
        return ['secondes' => 'integer', 'ordre' => 'integer'];
    }

    /** @return Collection<int, self> les durées, dans l'ordre (lues une fois par requête) */
    public static function toutes(): Collection
    {
        return MemoRequete::retenir('durees', fn () => static::query()->orderBy('ordre')->get());
    }

    public static function parCode(string $code): ?self
    {
        return self::toutes()->firstWhere('code', $code);
    }

    public static function parId(?int $id): ?self
    {
        return $id ? self::toutes()->firstWhere('id', $id) : null;
    }

    /** Valeur convertie en secondes. */
    public function enSecondes(int $valeur): int
    {
        return $valeur * $this->secondes;
    }

    /** « 1 heure », « 72 heures », « 30 jours ». */
    public function libelle(int $valeur): string
    {
        return format_nombre($valeur).' '.($valeur > 1 ? $this->libelle_pluriel : $this->libelle);
    }

    /** Délai lisible à partir d'une valeur et d'un code de durée. */
    public static function formater(int $valeur, string $code): string
    {
        return self::parCode($code)?->libelle($valeur) ?? format_nombre($valeur).' '.$code;
    }
}
