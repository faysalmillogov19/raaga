<?php

namespace App\Models\Concerns;

use App\Enums\StatutTypeBoutique;
use App\Models\ReferentielVersion;
use App\Models\User;
use App\Services\Backoffice\RegleGestionException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Référentiel géré par le GESTIONNAIRE DES SITES ET BOUTIQUES et validé par le VALIDATEUR (niveaux, zones) :
 * code jamais modifié ; utilisable seulement validé et actif ; une version figée à chaque changement.
 * Même circuit que les types de boutiques.
 */
trait ReferentielGere
{
    /** Type d'objet dans les versions (« niveau », « zone »). */
    abstract public static function typeReferentiel(): string;

    /** Champs figés dans chaque version. @return list<string> */
    abstract protected function champsVersion(): array;

    protected static function bootReferentielGere(): void
    {
        static::updating(function ($element) {
            if ($element->isDirty('code')) {
                throw new RegleGestionException(__('admin.zones.regles.code_fige'));
            }
        });
    }

    public function scopeUtilisables(Builder $requete): Builder
    {
        return $requete->where('statut', StatutTypeBoutique::Valide)->where('actif', true);
    }

    public function estUtilisable(): bool
    {
        return $this->statut === StatutTypeBoutique::Valide && $this->actif;
    }

    /** Motif de refus d'un élément non utilisable, ou null. */
    public function motifInutilisable(): ?string
    {
        $cle = 'admin.zones.regles.'.static::typeReferentiel().'_';

        return match (true) {
            $this->statut !== StatutTypeBoutique::Valide => __($cle.'non_valide', ['code' => $this->code]),
            ! $this->actif => __($cle.'desactive', ['code' => $this->code]),
            default => null,
        };
    }

    public function creerVersion(?User $auteur, string $motif): ReferentielVersion
    {
        $numero = (int) ReferentielVersion::query()->where('objet_type', static::typeReferentiel())->where('objet_id', $this->id)->max('numero') + 1;
        $version = ReferentielVersion::create([
            'objet_type' => static::typeReferentiel(), 'objet_id' => $this->id, 'numero' => $numero, 'auteur_id' => $auteur?->id,
            'motif' => $motif, 'created_at' => now(),
            'parametres' => collect($this->only($this->champsVersion()))->map(fn ($v) => $v instanceof \BackedEnum ? $v->value : $v)->all(),
        ]);
        $this->forceFill(['version' => $numero])->saveQuietly();

        return $version;
    }

    /** @return Collection<int, ReferentielVersion> */
    public function versions(): Collection
    {
        return ReferentielVersion::query()->where('objet_type', static::typeReferentiel())->where('objet_id', $this->id)->with('auteur')->orderByDesc('numero')->get();
    }
}
