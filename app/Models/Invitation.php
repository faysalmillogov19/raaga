<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['user_id', 'jeton_empreinte', 'expire_le', 'utilisee_le', 'annulee_le', 'cree_par'])]
#[Hidden(['jeton_empreinte'])]
class Invitation extends Model
{
    protected function casts(): array
    {
        return [
            'expire_le' => 'datetime',
            'utilisee_le' => 'datetime',
            'annulee_le' => 'datetime',
        ];
    }

    public static function empreinte(string $jeton): string
    {
        return hash('sha256', $jeton);
    }

    public function estValide(): bool
    {
        return $this->utilisee_le === null && $this->annulee_le === null && $this->expire_le->isFuture();
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
