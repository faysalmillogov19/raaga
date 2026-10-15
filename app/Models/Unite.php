<?php

namespace App\Models;

use App\Enums\StatutTypeBoutique;
use App\Enums\UniteLoyer;
use App\Models\Concerns\ReferentielGere;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * Unité de loyer de la grille tarifaire (au m², à l'unité, redevance…). Saisie par le RÉGULATEUR, validée par le
 * VALIDATEUR, code jamais modifié (voir ReferentielGere). Le calcul des montants dépend du mode de l'unité
 * (App\Enums\UniteLoyer) : seules les unités dont le code correspond à un mode de calcul sont proposées dans la grille.
 */
#[Table(name: 'unites')]
#[Fillable(['code', 'libelle', 'description', 'actif', 'par_defaut', 'statut'])]
class Unite extends Model
{
    use ReferentielGere;

    /** Code d'unité : 1 à 10 lettres majuscules ou chiffres (M2, UNITE, REDEVANCE). */
    public const FORMAT_CODE = '/^[A-Z0-9]{1,10}$/';

    protected function casts(): array
    {
        return ['actif' => 'boolean', 'par_defaut' => 'boolean', 'statut' => StatutTypeBoutique::class, 'valide_le' => 'datetime', 'version' => 'integer'];
    }

    public static function typeReferentiel(): string
    {
        return 'unite';
    }

    protected function champsVersion(): array
    {
        return ['code', 'libelle', 'description', 'actif', 'statut'];
    }

    /** Mode de calcul de l'unité (null : aucun calcul connu, l'unité n'est pas proposée dans la grille). */
    public function calcul(): ?UniteLoyer
    {
        return UniteLoyer::tryFrom(mb_strtolower($this->code));
    }

    /** Lignes de grille tarifaire qui utilisent cette unité. */
    public function nombreUtilisations(): int
    {
        return $this->calcul() ? GrilleTarifaireLigne::query()->where('unite_loyer', $this->calcul()->value)->count() : 0;
    }

    /**
     * Unités proposées dans la grille tarifaire : validées, actives et associées à un mode de calcul.
     *
     * @return Collection<string, string> valeur enregistrée dans la grille => libellé
     */
    public static function pourGrille(): Collection
    {
        return static::query()->utilisables()->orderBy('id')->get()
            ->filter(fn (Unite $u) => $u->calcul() !== null)
            ->mapWithKeys(fn (Unite $u) => [$u->calcul()->value => $u->libelle]);
    }

    /** Libellé d'une valeur de la grille (« m2 »), lu dans le référentiel ; à défaut, celui du mode de calcul. */
    public static function libelle(UniteLoyer $unite): string
    {
        $libelles = once(fn () => static::query()->pluck('libelle', 'code')->all());

        return $libelles[mb_strtoupper($unite->value)] ?? $unite->label();
    }
}
