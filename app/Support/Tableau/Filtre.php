<?php

namespace App\Support\Tableau;

use Closure;
use Illuminate\Database\Eloquent\Builder;

/** Filtre en liste déroulante au-dessus d'un tableau : seules les valeurs proposées sont acceptées. */
final class Filtre
{
    /** Filtre appliqué depuis l'adresse (lien d'un autre écran) mais absent des listes affichées. */
    public bool $masque = false;

    /** @param array<string|int, string> $options valeur => libellé */
    private function __construct(public readonly string $cle, public readonly string $libelle, public readonly array $options, private readonly Closure $appliquer) {}

    public function masque(): self
    {
        $this->masque = true;

        return $this;
    }

    /**
     * @param  array<string|int, string>  $options
     * @param  string|Closure  $appliquer  colonne SQL (égalité) ou fermeture (Builder, valeur)
     */
    public static function make(string $cle, string $libelle, array $options, string|Closure $appliquer): self
    {
        return new self($cle, $libelle, $options, $appliquer instanceof Closure ? $appliquer : fn (Builder $q, string $v) => $q->where($appliquer, $v));
    }

    /** Valeur reçue si elle fait partie des options, sinon null. */
    public function valeurDe(mixed $valeur): ?string
    {
        return is_scalar($valeur) && $valeur !== '' && array_key_exists((string) $valeur, $this->options) ? (string) $valeur : null;
    }

    public function appliquer(Builder $requete, string $valeur): void
    {
        ($this->appliquer)($requete, $valeur);
    }
}
