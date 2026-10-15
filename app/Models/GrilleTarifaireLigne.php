<?php

namespace App\Models;

use App\Enums\UniteLoyer;
use App\Models\Concerns\AvecNiveau;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Ligne de grille : un niveau × un type, unité (clé étrangère vers le référentiel « Unités ») et loyer par mois,
 * mois de caution, mois d'avance.
 */
#[Table(name: 'grille_tarifaire_lignes')]
#[Fillable(['grille_tarifaire_id', 'niveau', 'type_boutique_id', 'unite_id', 'loyer_m2', 'mois_caution', 'mois_avance'])]
class GrilleTarifaireLigne extends Model
{
    use AvecNiveau;

    protected function casts(): array
    {
        return [
            'loyer_m2' => 'integer',
            'mois_caution' => 'integer',
            'mois_avance' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<GrilleTarifaire, $this>
     */
    public function grille(): BelongsTo
    {
        return $this->belongsTo(GrilleTarifaire::class, 'grille_tarifaire_id');
    }

    /**
     * @return BelongsTo<Unite, $this>
     */
    public function unite(): BelongsTo
    {
        return $this->belongsTo(Unite::class);
    }

    /** Mode de calcul du loyer : celui de l'unité, à défaut au m². */
    public function modeCalcul(): UniteLoyer
    {
        return $this->unite?->calcul() ?? UniteLoyer::M2;
    }

    /**
     * @return BelongsTo<TypeBoutique, $this>
     */
    public function type(): BelongsTo
    {
        return $this->belongsTo(TypeBoutique::class, 'type_boutique_id');
    }
}
