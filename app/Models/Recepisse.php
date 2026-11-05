<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Récépissé de préinscription Raaga (réunion du 05/11/2026) : remis à une personne physique inscrite sur une boutique
 * sans IFU. Numéro propre (RP-AAAA-000123), jamais un numéro de commerçant ni imprimé sur la carte ; jeton du QR code
 * de vérification ; échéance de la formalisation.
 */
#[Fillable(['numero', 'jeton', 'user_id', 'inscription_boutique_id', 'boutique_id', 'emis_le', 'echeance_le'])]
class Recepisse extends Model
{
    protected function casts(): array
    {
        return ['emis_le' => 'datetime', 'echeance_le' => 'date'];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Boutique, $this> */
    public function boutique(): BelongsTo
    {
        return $this->belongsTo(Boutique::class);
    }

    /** @return BelongsTo<InscriptionBoutique, $this> */
    public function inscription(): BelongsTo
    {
        return $this->belongsTo(InscriptionBoutique::class, 'inscription_boutique_id');
    }

    public function urlVerification(): string
    {
        return route('recepisses.verifier', $this->jeton);
    }
}
