<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Import de boutiques déposé, en attente de confirmation : rien n'est enregistré tant qu'il n'est pas confirmé.
 * Expire au bout de 24 heures (fichier conservé) ; un nouvel import du même site (ou un nouvel import de plusieurs
 * sites) annule l'aperçu précédent.
 */
class ImportEnAttente extends Model
{
    public const EN_ATTENTE = 'EN_ATTENTE';

    public const CONFIRME = 'CONFIRME';

    public const ANNULE = 'ANNULE';

    public const EXPIRE = 'EXPIRE';

    public const DUREE_HEURES = 24;

    protected $table = 'imports_en_attente';

    protected $fillable = ['entite', 'site_id', 'user_id', 'chemin', 'nom', 'empreinte', 'statut', 'expire_le', 'termine_le'];

    protected function casts(): array
    {
        return ['expire_le' => 'datetime', 'termine_le' => 'datetime'];
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function auteur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /** Imports encore en attente et non expirés. */
    public function scopeActifs(Builder $requete): Builder
    {
        return $requete->where('statut', self::EN_ATTENTE)->where('expire_le', '>', now());
    }

    /** Passe en « Expiré » les imports en attente depuis plus de 24 heures. */
    public static function expirer(): void
    {
        static::query()->where('statut', self::EN_ATTENTE)->where('expire_le', '<=', now())
            ->update(['statut' => self::EXPIRE, 'termine_le' => now()]);
    }

    public function estActif(): bool
    {
        return $this->statut === self::EN_ATTENTE && $this->expire_le->isFuture();
    }

    public function terminer(string $statut): void
    {
        $this->forceFill(['statut' => $statut, 'termine_le' => now()])->save();
    }
}
