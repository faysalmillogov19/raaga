<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Correction d'un paiement déjà confirmé (paiement finalement non reçu), proposée par le CONTRÔLEUR DES PAIEMENTS et
 * confirmée ou rejetée par le VALIDATEUR dans « À valider › Paiements » ; jamais par son auteur.
 */
#[Table(name: 'corrections_paiement')]
#[Fillable(['paiement_id', 'motif', 'statut', 'propose_par', 'propose_le', 'decide_par', 'decide_le', 'motif_decision'])]
class CorrectionPaiement extends Model
{
    public const PROPOSEE = 'PROPOSEE';

    public const CONFIRMEE = 'CONFIRMEE';

    public const REJETEE = 'REJETEE';

    protected function casts(): array
    {
        return ['propose_le' => 'datetime', 'decide_le' => 'datetime'];
    }

    /**
     * @return BelongsTo<Paiement, $this>
     */
    public function paiement(): BelongsTo
    {
        return $this->belongsTo(Paiement::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function auteur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'propose_par');
    }
}
