<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Décision de phase du site public : présentation des actions visible, souscription d'actions ouverte.
 * Proposée par le SUPER_ADMIN, confirmée ou rejetée par le VALIDATEUR ; la dernière décision confirmée s'applique.
 */
class DecisionPhase extends Model
{
    public const SOUMISE = 'SOUMISE';

    public const CONFIRMEE = 'CONFIRMEE';

    public const REJETEE = 'REJETEE';

    protected $table = 'decisions_phase';

    protected $fillable = ['presentation_actions', 'souscription_actions', 'statut', 'motif', 'propose_par', 'decide_par', 'decide_le', 'motif_rejet'];

    protected function casts(): array
    {
        return ['presentation_actions' => 'boolean', 'souscription_actions' => 'boolean', 'decide_le' => 'datetime'];
    }

    public function auteur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'propose_par');
    }

    public function decideur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decide_par');
    }
}
