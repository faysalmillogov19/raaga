<?php

namespace App\Models;

use App\Enums\StatutTypeBoutique;
use App\Models\Concerns\ReferentielGere;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * Zone d'un site (bloc A, pôle boucherie-poissonnerie…), utilisée par les capacités prévues et comme bloc des
 * boutiques. Code jamais modifié. Référentiel géré (voir ReferentielGere).
 */
#[Table(name: 'zones')]
#[Fillable(['code', 'libelle', 'description', 'ordre', 'actif', 'par_defaut', 'statut'])]
class ZoneSite extends Model
{
    use ReferentielGere;

    /** Code de zone : 1 à 5 lettres majuscules ou chiffres (A, MAG, BP). */
    public const FORMAT_CODE = '/^[A-Z0-9]{1,5}$/';

    protected function casts(): array
    {
        return ['ordre' => 'integer', 'actif' => 'boolean', 'par_defaut' => 'boolean', 'statut' => StatutTypeBoutique::class, 'valide_le' => 'datetime', 'version' => 'integer'];
    }

    public static function typeReferentiel(): string
    {
        return 'zone';
    }

    protected function champsVersion(): array
    {
        return ['code', 'libelle', 'description', 'ordre', 'actif', 'statut'];
    }

    public function nombreCapacites(): int
    {
        return CapaciteSite::query()->where('zone', $this->code)->count();
    }

    /** Boutiques dont le bloc est cette zone (même supprimées). */
    public function nombreBoutiques(): int
    {
        return Boutique::withTrashed()->where('bloc', $this->code)->count();
    }

    /** Utilisée par une capacité prévue ou comme bloc d'une boutique. */
    public function nombreUtilisations(): int
    {
        return $this->nombreCapacites() + $this->nombreBoutiques();
    }
}
