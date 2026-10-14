<?php

namespace App\Support\Tableau;

use App\Enums\Droit;
use Closure;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\HtmlString;

/**
 * Colonne d'un tableau de données : libellé, valeur affichée, valeur exportée, tri et recherche côté serveur,
 * droit d'accès. Une colonne non autorisée pour le profil n'est ni calculée (préparation de requête ignorée),
 * ni affichée, ni exportée.
 */
final class Colonne
{
    private ?Closure $valeur = null;

    private ?Closure $texte = null;

    private ?Closure $badge = null;

    private ?string $vue = null;

    private string $variableVue = 'ligne';

    /** Tri : expression SQL, ou fermeture (Builder, 'asc'|'desc'). */
    private string|Closure|null $tri = null;

    /** Recherche : colonnes SQL comparées avec LIKE, ou fermeture (Builder, terme). */
    private array|Closure|null $recherche = null;

    private ?Closure $preparation = null;

    private ?Droit $droit = null;

    private bool $numerique = false;

    private bool $exportable = true;

    private string $classe = '';

    private function __construct(public readonly string $cle, public readonly string $libelle) {}

    public static function make(string $cle, string $libelle): self
    {
        return new self($cle, $libelle);
    }

    /** Valeur affichée : texte (échappé) ou Htmlable. Par défaut, l'attribut du même nom. */
    public function valeur(Closure $valeur): self
    {
        $this->valeur = $valeur;

        return $this;
    }

    /** Valeur exportée et lue sur mobile ; par défaut, le texte de la valeur affichée. */
    public function texte(Closure $texte): self
    {
        $this->texte = $texte;

        return $this;
    }

    /** Statut en badge texte : fermeture qui rend [libellé, variante] (succes, attention, echec, neutre). */
    public function badge(Closure $badge): self
    {
        $this->badge = $badge;

        return $this;
    }

    /** Valeur rendue par une vue partielle (boutons d'action…), la ligne étant passée sous le nom $variable. */
    public function vue(string $vue, string $variable = 'ligne'): self
    {
        $this->vue = $vue;
        $this->variableVue = $variable;

        return $this;
    }

    public function triable(string|Closure|null $tri = null): self
    {
        $this->tri = $tri ?? $this->cle;

        return $this;
    }

    public function recherchable(string|array|Closure|null $recherche = null): self
    {
        $this->recherche = $recherche instanceof Closure ? $recherche : (array) ($recherche ?? $this->cle);

        return $this;
    }

    /** Préparation de la requête propre à la colonne (with, withCount, addSelect), appliquée seulement si elle est visible. */
    public function avec(Closure $preparation): self
    {
        $this->preparation = $preparation;

        return $this;
    }

    public function droit(?Droit $droit): self
    {
        $this->droit = $droit;

        return $this;
    }

    public function numerique(bool $numerique = true): self
    {
        $this->numerique = $numerique;

        return $this;
    }

    public function exportable(bool $exportable = true): self
    {
        $this->exportable = $exportable;

        return $this;
    }

    public function classe(string $classe): self
    {
        $this->classe = $classe;

        return $this;
    }

    // ------------------------------------------------------------------ Lecture

    public function autorisee(): bool
    {
        return $this->droit === null || Gate::allows($this->droit->value);
    }

    public function estTriable(): bool
    {
        return $this->tri !== null;
    }

    public function estRecherchable(): bool
    {
        return $this->recherche !== null;
    }

    public function estNumerique(): bool
    {
        return $this->numerique;
    }

    public function estExportable(): bool
    {
        return $this->exportable;
    }

    public function classes(): string
    {
        return trim(($this->numerique ? 'montant ' : '').$this->classe);
    }

    public function preparer(Builder $requete): void
    {
        if ($this->preparation) {
            ($this->preparation)($requete);
        }
    }

    public function trier(Builder $requete, string $sens): void
    {
        $this->tri instanceof Closure ? ($this->tri)($requete, $sens) : $requete->orderBy($this->tri, $sens);
    }

    /** Condition « OU » de la recherche globale, ajoutée au groupe de recherche. */
    public function chercher(Builder $requete, string $terme): void
    {
        if ($this->recherche instanceof Closure) {
            $requete->orWhere(fn (Builder $q) => ($this->recherche)($q, $terme));

            return;
        }
        foreach ($this->recherche as $colonne) {
            $requete->orWhere($colonne, 'like', '%'.addcslashes($terme, '%_\\').'%');
        }
    }

    public function rendre(Model $ligne): Htmlable|string
    {
        if ($this->vue) {
            return new HtmlString(view($this->vue, [$this->variableVue => $ligne])->render());
        }
        if ($this->badge) {
            [$libelle, $variante] = ($this->badge)($ligne);

            return new HtmlString('<span class="bo-etiquette bo-etiquette-'.e($variante).'">'.e($libelle).'</span>');
        }
        $valeur = $this->valeur ? ($this->valeur)($ligne) : $ligne->getAttribute($this->cle);

        return $valeur instanceof Htmlable ? $valeur : (string) ($valeur ?? '—');
    }

    /** Texte brut : export Excel. */
    public function exporter(Model $ligne): string|int|float|null
    {
        if ($this->texte) {
            return ($this->texte)($ligne);
        }
        if ($this->badge) {
            return ($this->badge)($ligne)[0];
        }
        $valeur = $this->rendre($ligne);
        $texte = trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($valeur instanceof Htmlable ? $valeur->toHtml() : $valeur))));

        return $texte === '—' ? null : $texte;
    }
}
