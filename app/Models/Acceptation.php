<?php

namespace App\Models;

use App\Enums\DocumentAccepte;
use App\Models\Concerns\AppendOnly;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Table(timestamps: false)]
#[Fillable([
    'user_id', 'souscription_id', 'document', 'version_document', 'empreinte_document',
    'accepte_le', 'ip', 'user_agent', 'created_at',
])]
class Acceptation extends Model
{
    use AppendOnly;

    protected function casts(): array
    {
        return [
            'document' => DocumentAccepte::class,
            'accepte_le' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<Souscription, $this>
     */
    public function souscription(): BelongsTo
    {
        return $this->belongsTo(Souscription::class);
    }
}
