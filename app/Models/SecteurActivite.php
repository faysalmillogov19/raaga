<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Secteur d'activité d'un commerçant, rattaché à sa famille, rempli par SecteursActiviteSeeder. « Autre activité »
 * (code AUTRE) demande une précision libre.
 */
#[Table(name: 'secteurs_activite')]
#[Fillable(['famille_secteur_id', 'code', 'libelle', 'ordre', 'actif'])]
class SecteurActivite extends Model
{
    public const AUTRE = 'AUTRE';

    protected function casts(): array
    {
        return ['actif' => 'boolean'];
    }

    /** @return BelongsTo<FamilleSecteur, $this> */
    public function famille(): BelongsTo
    {
        return $this->belongsTo(FamilleSecteur::class, 'famille_secteur_id');
    }

    /**
     * Secteurs actifs à proposer, groupés par famille (« Alimentation » => [id => libellé]), dans l'ordre des familles et
     * des secteurs.
     *
     * @return array<string, array<int, string>>
     */
    public static function parFamille(): array
    {
        return static::query()->where('secteurs_activite.actif', true)
            ->join('familles_secteurs', 'familles_secteurs.id', '=', 'secteurs_activite.famille_secteur_id')->where('familles_secteurs.actif', true)
            ->orderBy('familles_secteurs.ordre')->orderBy('secteurs_activite.ordre')
            ->get(['secteurs_activite.id', 'secteurs_activite.libelle', 'familles_secteurs.libelle as famille'])
            ->groupBy('famille')->map(fn ($secteurs) => $secteurs->pluck('libelle', 'id')->all())->all();
    }

    /** Libellé à afficher : la précision libre pour « Autre activité ». */
    public static function libellePour(User $user): ?string
    {
        $secteur = $user->secteur;

        return $secteur?->code === self::AUTRE && filled($user->secteur_activite_autre) ? $user->secteur_activite_autre : $secteur?->libelle;
    }
}
