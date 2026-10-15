<?php

namespace App\Models;

use App\Models\Concerns\AppendOnly;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Version figée des paramètres d'investissement, créée à chaque soumission (ajout seul). */
#[Table(name: 'parametres_investissement_versions', timestamps: false)]
#[Fillable(['parametres_investissement_id', 'numero', 'parametres', 'auteur_id', 'motif', 'created_at'])]
class ParametresInvestissementVersion extends Model
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
