<?php

namespace App\Models;

use App\Models\Concerns\AppendOnly;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Version figée d'une grille tarifaire (lignes comprises), créée à chaque soumission. */
#[Table(name: 'grille_tarifaire_versions', timestamps: false)]
#[Fillable(['grille_tarifaire_id', 'numero', 'parametres', 'auteur_id', 'motif', 'created_at'])]
class GrilleTarifaireVersion extends Model
{
    use AppendOnly;

    protected function casts(): array
    {
        return ['numero' => 'integer', 'parametres' => 'array', 'created_at' => 'datetime'];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function auteur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'auteur_id');
    }
}
