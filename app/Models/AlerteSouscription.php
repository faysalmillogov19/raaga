<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Inscription à l'alerte SMS « souscription d'actions ouverte » (numéro au format international, consentement daté). */
class AlerteSouscription extends Model
{
    protected $table = 'alertes_souscription';

    protected $fillable = ['telephone', 'consentement_le', 'prevenu_le'];

    protected function casts(): array
    {
        return ['consentement_le' => 'datetime', 'prevenu_le' => 'datetime'];
    }
}
