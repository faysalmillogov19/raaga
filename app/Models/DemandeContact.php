<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Message envoyé depuis la page « Nous contacter » ; son numéro de suivi est transmis par SMS. */
class DemandeContact extends Model
{
    public const OBJETS = ['compte', 'souscription', 'paiement', 'fraude', 'autre'];

    protected $table = 'demandes_contact';

    protected $fillable = ['user_id', 'nom', 'telephone', 'email', 'objet', 'message'];

    /** Numéro de suivi lisible au téléphone : « C-261015-00042 ». */
    public static function numeroPour(self $demande): string
    {
        return 'C-'.$demande->created_at->format('ymd').'-'.str_pad((string) $demande->id, 5, '0', STR_PAD_LEFT);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
