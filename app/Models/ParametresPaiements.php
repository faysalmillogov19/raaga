<?php

namespace App\Models;

use App\Contracts\Parametrable;
use App\Enums\DecisionParametrage;
use App\Enums\StatutGrilleTarifaire;
use App\Support\MemoRequete;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * Paramètres des paiements (une seule fiche) : délai de complément d'un paiement manuel incomplet. Saisis par le
 * RÉGULATEUR, confirmés par le VALIDATEUR (workflow commun, versions figées). Tant que rien n'est confirmé : 48 h
 * (config raaga.paiement.delai_complement_heures).
 */
#[Table(name: 'parametres_paiements')]
#[Fillable(['delai_complement_heures', 'statut', 'version', 'soumis_par', 'soumis_le', 'valide_par', 'valide_le', 'motif_rejet'])]
class ParametresPaiements extends Model implements Parametrable
{
    public const PARAMETRES = ['delai_complement_heures'];

    protected function casts(): array
    {
        return [
            'statut' => StatutGrilleTarifaire::class,
            'delai_complement_heures' => 'integer',
            'version' => 'integer',
            'soumis_le' => 'datetime',
            'valide_le' => 'datetime',
        ];
    }

    /** La fiche unique (créée vide, en brouillon, à la première saisie). */
    public static function fiche(): self
    {
        return static::query()->orderBy('id')->first() ?? new self(['statut' => StatutGrilleTarifaire::Brouillon]);
    }

    /** @return array{delai_complement_heures: int}|null valeurs de la dernière version confirmée (lues une fois par page) */
    public static function confirmes(): ?array
    {
        return MemoRequete::retenir('parametres_paiements.confirmes', function () {
            $fiche = static::query()->orderBy('id')->first();
            $numero = $fiche ? ParametrageDecision::query()->where('objet_type', 'delais')->where('objet_id', $fiche->id)
                ->where('decision', DecisionParametrage::Confirme)->latest('id')->value('version') : null;
            $p = $numero ? $fiche->versions()->where('numero', $numero)->value('parametres') : null;
            $p = is_string($p) ? json_decode($p, true) : $p;

            return $p && ($p['delai_complement_heures'] ?? null) ? ['delai_complement_heures' => (int) $p['delai_complement_heures']] : null;
        });
    }

    /** Délai de complément en vigueur, en heures : valeur confirmée, sinon 48 h. */
    public static function delaiComplementHeures(): int
    {
        return self::confirmes()['delai_complement_heures'] ?? (int) config('raaga.paiement.delai_complement_heures', 48);
    }

    public function typeParametrage(): string
    {
        return 'delais';
    }

    public function estModifiable(): bool
    {
        return $this->statut === StatutGrilleTarifaire::Brouillon;
    }

    public function estSoumis(): bool
    {
        return $this->statut === StatutGrilleTarifaire::Soumis;
    }

    public function marquerSoumis(User $auteur): void
    {
        $this->forceFill(['statut' => StatutGrilleTarifaire::Soumis, 'soumis_par' => $auteur->id, 'soumis_le' => now(), 'motif_rejet' => null])->save();
    }

    public function marquerConfirme(User $validateur): void
    {
        $this->forceFill(['statut' => StatutGrilleTarifaire::Confirme, 'valide_par' => $validateur->id, 'valide_le' => now()])->save();
    }

    /** Retiré de la validation par son auteur, avant toute décision : retour au brouillon. */
    public function marquerRetire(): void
    {
        $this->forceFill(['statut' => StatutGrilleTarifaire::Brouillon])->save();
    }

    public function marquerRejete(string $motif): void
    {
        $this->forceFill(['statut' => StatutGrilleTarifaire::Brouillon, 'motif_rejet' => $motif])->save();
    }

    /** Pas d'avenant : des paramètres confirmés se remettent en révision, puis se soumettent de nouveau. */
    public function champsAvenantAutorises(): ?array
    {
        return [];
    }

    public function appliquerAvenant(array $valeurs): void {}

    public function creerVersion(User $auteur, ?string $motif = null): ParametresPaiementsVersion
    {
        $numero = (int) $this->versions()->max('numero') + 1;
        $version = ParametresPaiementsVersion::create([
            'parametres_paiements_id' => $this->id, 'numero' => $numero, 'parametres' => $this->snapshotParametres(),
            'auteur_id' => $auteur->id, 'motif' => $motif, 'created_at' => now(),
        ]);
        $this->forceFill(['version' => $numero])->save();

        return $version;
    }

    /** @return array<string, mixed> */
    public function snapshotParametres(): array
    {
        return $this->only(self::PARAMETRES);
    }

    /**
     * @return HasMany<ParametresPaiementsVersion, $this>
     */
    public function versions(): HasMany
    {
        return $this->hasMany(ParametresPaiementsVersion::class)->orderBy('numero');
    }

    public function avenants(): MorphMany
    {
        return $this->morphMany(Avenant::class, 'avenantable')->latest('id');
    }
}
