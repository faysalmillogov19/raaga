<?php

namespace App\Models;

use App\Enums\StatutBoutique;
use App\Models\Concerns\AppendOnly;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;

/** Changement de statut commercial d'une boutique (ajout seul) : pré-réservation, paiement, attribution, expiration, annulation. */
#[Table(name: 'boutique_statuts', timestamps: false)]
#[Fillable(['boutique_id', 'ancien', 'nouveau', 'reservation_boutique_id', 'auteur_id', 'motif', 'created_at'])]
class BoutiqueStatut extends Model
{
    use AppendOnly;

    protected function casts(): array
    {
        return ['ancien' => StatutBoutique::class, 'nouveau' => StatutBoutique::class, 'created_at' => 'datetime'];
    }
}
