<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Décision de l'ADMIN sur un dossier d'identité : CONFORME (identité validée), ou DOUTEUX (proposition d'annulation,
 * confirmée ou rejetée par le VALIDATEUR dans « À valider › Identités »). Aucun montant n'y figure.
 */
#[Table(name: 'verifications_identite')]
#[Fillable(['user_id', 'decision', 'motif', 'decide_par', 'statut', 'valide_par', 'valide_le', 'motif_validateur'])]
class VerificationKyc extends Model
{
    public const CONFORME = 'CONFORME';

    public const DOUTEUX = 'DOUTEUX';

    public const ANNULATION_PROPOSEE = 'ANNULATION_PROPOSEE';

    public const ANNULATION_CONFIRMEE = 'ANNULATION_CONFIRMEE';

    public const ANNULATION_REJETEE = 'ANNULATION_REJETEE';

    protected function casts(): array
    {
        return ['valide_le' => 'datetime'];
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
    public function auteur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decide_par');
    }
}
