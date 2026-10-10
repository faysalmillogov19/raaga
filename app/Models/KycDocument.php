<?php

namespace App\Models;

use App\Enums\FaceDocument;
use App\Enums\StatutDocumentKyc;
use App\Enums\TypeDocumentKyc;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'user_id', 'type', 'face', 'chemin_fichier', 'nom_original', 'mime', 'taille',
    'empreinte_sha256', 'statut', 'commentaire', 'televerse_par', 'valide_par', 'valide_le',
])]
#[Hidden(['chemin_fichier'])]
class KycDocument extends Model
{
    protected function casts(): array
    {
        return [
            'type' => TypeDocumentKyc::class,
            'face' => FaceDocument::class,
            'statut' => StatutDocumentKyc::class,
            'taille' => 'integer',
            'valide_le' => 'datetime',
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
     * @return BelongsTo<User, $this>
     */
    public function validateur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'valide_par');
    }
}
