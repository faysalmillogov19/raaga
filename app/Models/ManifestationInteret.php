<?php

namespace App\Models;

use App\Enums\ProfilInvestisseur;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * Manifestation d'intérêt RAAGA Invest : simulation non engageante d'un citoyen connecté (profil, pays de résidence,
 * nombre d'actions envisagé). Une référence est attribuée ; aucun paiement, aucun droit à des actions ni à une boutique.
 */
#[Table(name: 'manifestations_interet')]
#[Fillable(['reference', 'user_id', 'profil', 'pays_residence', 'nombre_actions', 'valeur_action', 'montant', 'simulation_comprise_le'])]
class ManifestationInteret extends Model
{
    protected function casts(): array
    {
        return ['profil' => ProfilInvestisseur::class, 'nombre_actions' => 'integer', 'valeur_action' => 'integer', 'montant' => 'integer',
            'simulation_comprise_le' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::creating(fn (self $m) => $m->reference ??= self::nouvelleReference());
    }

    /** « INV-2026-7K3QX9 » : année et six caractères aléatoires. */
    public static function nouvelleReference(): string
    {
        do {
            $reference = 'INV-'.now()->format('Y').'-'.Str::upper(Str::random(6));
        } while (static::query()->where('reference', $reference)->exists());

        return $reference;
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
