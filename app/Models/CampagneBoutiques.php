<?php

namespace App\Models;

use App\Contracts\Parametrable;
use App\Enums\CanalPaiement;
use App\Enums\StatutCampagneBoutiques;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * Campagne d'attribution des boutiques du marché. Indépendante des actions : tout citoyen peut
 * candidater, sans avantage lié aux actions détenues. Caution et avance par lot (grille tarifaire) ; attribution par
 * tirage au sort. Paramétrage soumis au workflow commun (RÉGULATEUR saisit, VALIDATEUR confirme).
 */
#[Table(name: 'campagnes_boutiques')]
#[Fillable([
    'statut', 'nom', 'description', 'date_ouverture', 'date_cloture', 'delai_paiement_heures', 'canaux_actifs', 'max_boutiques_par_personne',
    'version', 'cree_par', 'soumis_par', 'soumise_le', 'valide_par', 'validee_le', 'motif_rejet',
    'ouverte_le', 'cloturee_le', 'attribuee_le',
])]
class CampagneBoutiques extends Model implements Parametrable
{
    public const PARAMETRES = ['nom', 'description', 'date_ouverture', 'date_cloture', 'delai_paiement_heures', 'canaux_actifs', 'max_boutiques_par_personne'];

    protected function casts(): array
    {
        return [
            'statut' => StatutCampagneBoutiques::class,
            'date_ouverture' => 'datetime',
            'date_cloture' => 'datetime',
            'delai_paiement_heures' => 'integer',
            'max_boutiques_par_personne' => 'integer',
            'canaux_actifs' => 'array',
            'version' => 'integer',
            'soumise_le' => 'datetime',
            'validee_le' => 'datetime',
            'ouverte_le' => 'datetime',
            'cloturee_le' => 'datetime',
            'attribuee_le' => 'datetime',
        ];
    }

    public static function ouverte(): ?self
    {
        return self::query()->where('statut', StatutCampagneBoutiques::Ouverte)->latest('date_ouverture')->first();
    }

    /** @return list<CanalPaiement> */
    public function canaux(): array
    {
        return array_values(array_filter(array_map(fn (string $c) => CanalPaiement::tryFrom($c), (array) $this->canaux_actifs)));
    }

    public function typeParametrage(): string
    {
        return 'campagne_boutiques';
    }

    public function estModifiable(): bool
    {
        return $this->statut === StatutCampagneBoutiques::Brouillon;
    }

    public function estSoumis(): bool
    {
        return $this->statut === StatutCampagneBoutiques::Soumise;
    }

    public function marquerSoumis(User $auteur): void
    {
        $this->forceFill(['statut' => StatutCampagneBoutiques::Soumise, 'soumis_par' => $auteur->id, 'soumise_le' => now(), 'motif_rejet' => null])->save();
    }

    public function marquerConfirme(User $validateur): void
    {
        $this->forceFill(['statut' => StatutCampagneBoutiques::Confirmee, 'valide_par' => $validateur->id, 'validee_le' => now()])->save();
    }

    /** Retiré de la validation par son auteur, avant toute décision : retour au brouillon. */
    public function marquerRetire(): void
    {
        $this->forceFill(['statut' => StatutCampagneBoutiques::Brouillon])->save();
    }

    public function marquerRejete(string $motif): void
    {
        $this->forceFill(['statut' => StatutCampagneBoutiques::Brouillon, 'motif_rejet' => $motif])->save();
    }

    /** Pas d'avenant : une campagne confirmée se rejette ou se recrée. */
    public function champsAvenantAutorises(): ?array
    {
        return [];
    }

    public function appliquerAvenant(array $valeurs): void {}

    public function creerVersion(User $auteur, ?string $motif = null): CampagneBoutiquesVersion
    {
        $numero = (int) $this->versions()->max('numero') + 1;

        $version = CampagneBoutiquesVersion::create([
            'campagne_boutiques_id' => $this->id,
            'numero' => $numero,
            'parametres' => $this->snapshotParametres(),
            'auteur_id' => $auteur->id,
            'motif' => $motif,
            'created_at' => now(),
        ]);

        $this->forceFill(['version' => $numero])->save();

        return $version;
    }

    /**
     * Paramètres figés : champs de la campagne et résumé lisible des catégories (pour le différentiel).
     *
     * @return array<string, mixed>
     */
    public function snapshotParametres(): array
    {
        $snapshot = [];
        foreach (self::PARAMETRES as $champ) {
            $valeur = $this->getAttribute($champ);
            $snapshot[$champ] = $valeur instanceof \DateTimeInterface ? $valeur->format(DATE_ATOM) : $valeur;
        }

        $snapshot['categories'] = $this->categories()->with('site')->get()
            ->map(fn (CategorieBoutique $c) => $c->intitule().' : '.format_nombre($c->nombre_boutiques).' boutique(s)'
                .($c->estTarife() ? ', caution '.format_fcfa($c->caution).($c->avance ? ', avance '.format_fcfa($c->avance) : '') : ''))
            ->implode(' ; ');

        return $snapshot;
    }

    /**
     * @return HasMany<CategorieBoutique, $this>
     */
    public function categories(): HasMany
    {
        return $this->hasMany(CategorieBoutique::class, 'campagne_boutiques_id')->orderBy('ordre');
    }

    /**
     * @return HasMany<CampagneBoutiquesVersion, $this>
     */
    public function versions(): HasMany
    {
        return $this->hasMany(CampagneBoutiquesVersion::class, 'campagne_boutiques_id')->orderBy('numero');
    }

    public function avenants(): MorphMany
    {
        return $this->morphMany(Avenant::class, 'avenantable')->latest('id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function createur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cree_par');
    }
}
