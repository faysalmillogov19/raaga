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
 * Paramètres d'investissement RAAGA Invest (une seule fiche) : objectif de collecte (peut être dépassé), valeur
 * indicative par action, nombre minimum d'actions (1 par défaut), plafond global facultatif (vide = sans plafond). Saisis par le RÉGULATEUR, confirmés par le
 * VALIDATEUR (workflow commun, versions figées) ; aucune valeur de départ. Le site public lit la dernière version
 * confirmée (confirmes()), même pendant une révision.
 */
#[Table(name: 'parametres_investissement')]
#[Fillable(['objectif_collecte', 'valeur_action', 'minimum_actions', 'plafond_global', 'statut', 'version', 'soumis_par', 'soumis_le', 'valide_par', 'valide_le', 'motif_rejet'])]
class ParametresInvestissement extends Model implements Parametrable
{
    public const PARAMETRES = ['objectif_collecte', 'valeur_action', 'minimum_actions', 'plafond_global'];

    protected function casts(): array
    {
        return [
            'statut' => StatutGrilleTarifaire::class,
            'objectif_collecte' => 'integer',
            'valeur_action' => 'integer',
            'minimum_actions' => 'integer',
            'plafond_global' => 'integer',
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

    /**
     * Valeurs de la dernière version confirmée, ou null si rien n'a encore été confirmé (lues une fois par page).
     *
     * @return array{objectif_collecte: int, valeur_action: int, minimum_actions: int, plafond_global: ?int}|null
     */
    public static function confirmes(): ?array
    {
        return MemoRequete::retenir('parametres_investissement.confirmes', function () {
            $fiche = static::query()->orderBy('id')->first();
            $numero = $fiche ? ParametrageDecision::query()->where('objet_type', 'investissement')->where('objet_id', $fiche->id)
                ->where('decision', DecisionParametrage::Confirme)->latest('id')->value('version') : null;
            $p = $numero ? $fiche->versions()->where('numero', $numero)->value('parametres') : null;
            $p = is_string($p) ? json_decode($p, true) : $p;

            return $p && ($p['objectif_collecte'] ?? null) && ($p['valeur_action'] ?? null)
                ? ['objectif_collecte' => (int) $p['objectif_collecte'], 'valeur_action' => (int) $p['valeur_action'],
                    // Versions confirmées avant l'ajout du minimum : 1 action.
                    'minimum_actions' => max(1, (int) ($p['minimum_actions'] ?? 1)),
                    'plafond_global' => isset($p['plafond_global']) ? (int) $p['plafond_global'] : null]
                : null;
        });
    }

    public function typeParametrage(): string
    {
        return 'investissement';
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

    public function creerVersion(User $auteur, ?string $motif = null): ParametresInvestissementVersion
    {
        $numero = (int) $this->versions()->max('numero') + 1;
        $version = ParametresInvestissementVersion::create([
            'parametres_investissement_id' => $this->id, 'numero' => $numero, 'parametres' => $this->snapshotParametres(),
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
     * @return HasMany<ParametresInvestissementVersion, $this>
     */
    public function versions(): HasMany
    {
        return $this->hasMany(ParametresInvestissementVersion::class)->orderBy('numero');
    }

    public function avenants(): MorphMany
    {
        return $this->morphMany(Avenant::class, 'avenantable')->latest('id');
    }
}
