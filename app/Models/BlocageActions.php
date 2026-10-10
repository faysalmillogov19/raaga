<?php

namespace App\Models;

use App\Enums\MotifBlocage;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Actions d'un actionnaire bloquées (succession en cours, litige) : son dividende est consigné
 * jusqu'au déblocage.
 */
#[Table(name: 'blocages_actions')]
#[Fillable(['user_id', 'motif', 'commentaire', 'debut', 'fin', 'cree_par'])]
class BlocageActions extends Model
{
    protected function casts(): array
    {
        return [
            'motif' => MotifBlocage::class,
            'debut' => 'date',
            'fin' => 'date',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
