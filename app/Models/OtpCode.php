<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;

#[Table(timestamps: false)]
#[Fillable(['telephone', 'objet', 'code_hash', 'expire_le', 'tentatives', 'utilise_le', 'created_at'])]
#[Hidden(['code_hash'])]
class OtpCode extends Model
{
    protected function casts(): array
    {
        return [
            'expire_le' => 'datetime',
            'tentatives' => 'integer',
            'utilise_le' => 'datetime',
            'created_at' => 'datetime',
        ];
    }
}
