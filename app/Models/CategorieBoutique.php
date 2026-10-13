<?php

namespace App\Models;

use App\Enums\NiveauBoutique;
use App\Support\MontantsBoutique;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Lot d'une campagne : boutiques identiques en prix — même site, type, niveau et surface. Le nombre de boutiques
 * est compté dans le référentiel ; loyer, caution et avance sont figés à l'ouverture depuis la grille confirmée.
 * (Les lots antérieurs aux grilles n'ont ni niveau ni surface : leur caution a été saisie.)
 */
#[Table(name: 'categories_boutiques')]
#[Fillable([
    'campagne_boutiques_id', 'site_id', 'type_boutique_id', 'niveau', 'surface', 'ordre', 'libelle', 'description', 'nombre_boutiques',
    'caution', 'grille_version', 'loyer_m2', 'loyer_mensuel', 'mois_caution', 'mois_avance', 'avance',
])]
class CategorieBoutique extends Model
{
    protected function casts(): array
    {
        return [
            'niveau' => NiveauBoutique::class,
            'surface' => 'decimal:2',
            'ordre' => 'integer',
            'nombre_boutiques' => 'integer',
            'caution' => 'integer',
            'grille_version' => 'integer',
            'loyer_m2' => 'integer',
            'loyer_mensuel' => 'integer',
            'mois_caution' => 'integer',
            'mois_avance' => 'integer',
            'avance' => 'integer',
        ];
    }

    /** Montants connus (figés à l'ouverture, ou saisis pour un lot antérieur aux grilles). */
    public function estTarife(): bool
    {
        return $this->caution !== null;
    }

    /** @return list<array{libelle: string, montant: int, mention: string}> */
    public function lignesMontants(): array
    {
        return MontantsBoutique::lignes($this->caution, $this->avance, $this->mois_avance);
    }

    /** Boutiques du référentiel qui composent le lot. @return Builder<Boutique> */
    public function requeteBoutiques(): Builder
    {
        $requete = Boutique::query()->where('site_id', $this->site_id)->where('type_boutique_id', $this->type_boutique_id);

        if ($this->niveau !== null) {
            $requete->where('niveau', $this->niveau)->where(fn ($q) => $this->surface === null
                ? $q->whereNull('surface') : $q->where('surface', $this->surface));
        }

        return $requete->orderByRaw('code IS NULL, code, LENGTH(numero), numero');
    }

    /**
     * @return BelongsTo<CampagneBoutiques, $this>
     */
    public function campagne(): BelongsTo
    {
        return $this->belongsTo(CampagneBoutiques::class, 'campagne_boutiques_id');
    }

    /**
     * @return BelongsTo<Site, $this>
     */
    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    /**
     * @return BelongsTo<TypeBoutique, $this>
     */
    public function type(): BelongsTo
    {
        return $this->belongsTo(TypeBoutique::class, 'type_boutique_id');
    }

    /** « Boutique · R+1 · 9 m² — Marché central » (le site est omis pour un lot sans site). */
    public function intitule(): string
    {
        return $this->libelle.($this->site ? ' — '.$this->site->nom : '');
    }
}
