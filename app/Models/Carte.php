<?php

namespace App\Models;

use App\Enums\StatutCarte;
use App\Enums\TypeCarte;
use App\Jobs\GenererFichiersCarte;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Carte d'accès Raaga (RG-C-000123) : type, titulaire, statut, validité, jeton du QR code (URL de vérification, sans
 * donnée personnelle), contenu affiché et version. Une seule carte courante par titulaire et par type.
 */
#[Fillable([
    'numero', 'type', 'user_id', 'statut', 'courante', 'jeton', 'emise_le', 'valide_jusqu_au', 'suspendue_le', 'revoquee_le',
    'motif', 'contenu', 'reglages_rendu', 'fichiers_echec_le', 'fichiers_echec_version', 'fichiers_echec_cause', 'version', 'emise_par', 'remplace_carte_id', 'revocation_demandee_le', 'revocation_demandee_par', 'revocation_motif',
    'wallet_google_classe', 'wallet_google_objet', 'wallet_apple_type_pass', 'wallet_apple_serie', 'wallet_apple_jeton_auth', 'wallet_mis_a_jour_le',
])]
#[Hidden(['jeton', 'wallet_apple_jeton_auth'])]
class Carte extends Model
{
    /** Valeur par défaut de la base, connue dès la création (version des fichiers générés). */
    protected $attributes = ['version' => 1];

    protected function casts(): array
    {
        return [
            'type' => TypeCarte::class,
            'statut' => StatutCarte::class,
            'courante' => 'boolean',
            'emise_le' => 'datetime',
            'valide_jusqu_au' => 'date',
            'suspendue_le' => 'datetime',
            'revoquee_le' => 'datetime',
            'contenu' => 'array',
            'reglages_rendu' => 'array',
            'fichiers_echec_le' => 'datetime',
            'fichiers_echec_version' => 'integer',
            'version' => 'integer',
            'wallet_mis_a_jour_le' => 'datetime',
            'revocation_demandee_le' => 'datetime',
        ];
    }

    /**
     * Fichiers de la carte (PNG, PDF) générés en tâche de fond, après validation de la transaction : à l'émission (carte
     * active numérotée), à chaque nouvelle version (données imprimées modifiées) et quand la carte redevient active.
     */
    protected static function booted(): void
    {
        $generer = function (Carte $carte): void {
            if ($carte->courante && $carte->statut === StatutCarte::Active && $carte->numero) {
                GenererFichiersCarte::dispatch($carte->id, $carte->version)->afterCommit();
            }
        };
        static::created($generer);
        static::updated(function (Carte $carte) use ($generer): void {
            if ($carte->wasChanged(['numero', 'version', 'statut', 'courante'])) {
                $generer($carte);
            }
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @param Builder<Carte> $requete */
    public function scopeCourantes(Builder $requete): void
    {
        $requete->where('courante', true);
    }

    /** Valide au scan : active et dans sa période de validité. */
    public function estValide(): bool
    {
        return $this->statut === StatutCarte::Active && ($this->valide_jusqu_au === null || ! $this->valide_jusqu_au->isPast() || $this->valide_jusqu_au->isToday());
    }

    /** Génération des fichiers de la version courante en échec définitif (trois essais), en attente d'une relance. */
    public function fichiersEnEchec(): bool
    {
        return $this->fichiers_echec_le !== null && $this->fichiers_echec_version === $this->version;
    }

    /** Adresse de vérification portée par le QR code (jeton seul). */
    public function urlVerification(): string
    {
        return route('cartes.verifier', $this->jeton);
    }
}
