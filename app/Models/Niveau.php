<?php

namespace App\Models;

use App\Enums\StatutTypeBoutique;
use App\Models\Concerns\ReferentielGere;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * Niveau d'un site (SS, RC, R1…), trié par rang (-1 sous-sol, 0 rez-de-chaussée, 1, 2…). Son code entre dans le code
 * des boutiques (SITE-NIVEAU-BLOC-NUMÉRO) : il ne change jamais. Référentiel géré (voir ReferentielGere).
 */
#[Table(name: 'niveaux')]
#[Fillable(['code', 'libelle', 'rang', 'actif', 'par_defaut', 'statut'])]
class Niveau extends Model
{
    use ReferentielGere;

    /** Code : 2 à 3 lettres majuscules ou chiffres (SS, RC, R1, R4). */
    public const FORMAT_CODE = '/^[A-Z0-9]{2,3}$/';

    protected function casts(): array
    {
        return ['rang' => 'integer', 'actif' => 'boolean', 'par_defaut' => 'boolean', 'statut' => StatutTypeBoutique::class, 'valide_le' => 'datetime', 'version' => 'integer'];
    }

    public static function typeReferentiel(): string
    {
        return 'niveau';
    }

    protected function champsVersion(): array
    {
        return ['code', 'libelle', 'rang', 'actif', 'statut'];
    }

    /** Libellé affiché. */
    public function label(): string
    {
        return $this->libelle;
    }

    /** Niveaux proposés dans les saisies, les imports et le modèle Excel, par rang. @return Collection<int, Niveau> */
    public static function listeUtilisable(): Collection
    {
        return static::query()->utilisables()->orderBy('rang')->orderBy('code')->get();
    }

    /** Libellés de tous les niveaux, actifs ou non (affichage des boutiques existantes). @return array<string, string> */
    public static function libelles(): array
    {
        return static::query()->pluck('libelle', 'code')->all();
    }

    /** Utilisé par une boutique (même supprimée), une capacité, une ligne ou une version de grille, ou un lot de campagne. */
    public function nombreUtilisations(): int
    {
        return Boutique::withTrashed()->where('niveau', $this->code)->count()
            + CapaciteSite::query()->where('niveau', $this->code)->count()
            + GrilleTarifaireLigne::query()->where('niveau', $this->code)->count()
            + GrilleTarifaireVersion::query()->where('parametres', 'like', '%"'.$this->code.'|%')->count()
            + CategorieBoutique::query()->where('niveau', $this->code)->count();
    }
}
