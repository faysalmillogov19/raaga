<?php

namespace App\Contracts;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * Objet soumis au workflow de paramétrage commun (tranche, distribution de dividendes) :
 * BROUILLON → SOUMIS → CONFIRMÉ, ou REJETÉ (retour en brouillon avec le motif).
 */
interface Parametrable
{
    /** Code court utilisé dans le journal d'audit et les décisions (« tranche », « distribution »). */
    public function typeParametrage(): string;

    /**
     * @return array<string, mixed>
     */
    public function snapshotParametres(): array;

    public function versions(): HasMany;

    public function avenants(): MorphMany;

    /** Fige les paramètres actuels dans une nouvelle version. */
    public function creerVersion(User $auteur, ?string $motif = null): Model;

    public function estModifiable(): bool;

    public function estSoumis(): bool;

    public function marquerSoumis(User $auteur): void;

    public function marquerConfirme(User $validateur): void;

    public function marquerRejete(string $motif): void;

    /**
     * Paramètres modifiables par avenant dans l'état actuel : null = tous, [] = aucun.
     *
     * @return list<string>|null
     */
    public function champsAvenantAutorises(): ?array;

    /**
     * @param  array<string, mixed>  $valeurs
     */
    public function appliquerAvenant(array $valeurs): void;
}
