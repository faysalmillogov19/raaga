<?php

namespace App\Services\Boutiques;

/** Résultat de l'analyse d'un fichier « Locaux » : lignes valides, erreurs (ligne, colonne, motif) et totaux. */
final class AnalyseImport
{
    /** @var list<array{ligne: int, code: string, boutique_id: ?int, donnees: array<string, mixed>}> */
    private array $lignes = [];

    /** @var list<array{ligne: ?int, colonne: ?string, motif: string}> */
    private array $erreurs = [];

    /** @var array<string, int> */
    private array $parNiveau = [];

    /** @var array<string, int> */
    private array $parType = [];

    public function erreur(?int $ligne, ?string $colonne, string $motif, array $parametres = []): self
    {
        $this->erreurs[] = ['ligne' => $ligne, 'colonne' => $colonne, 'motif' => strtr($motif, collect($parametres)->mapWithKeys(fn ($v, $k) => [':'.$k => $v])->all())];

        return $this;
    }

    public function ajouter(int $ligne, string $code, ?int $boutiqueId, array $donnees, string $niveau, string $type): void
    {
        $this->lignes[] = ['ligne' => $ligne, 'code' => $code, 'boutique_id' => $boutiqueId, 'donnees' => $donnees];
        $this->parNiveau[$niveau] = ($this->parNiveau[$niveau] ?? 0) + 1;
        $this->parType[$type] = ($this->parType[$type] ?? 0) + 1;
    }

    public function estValide(): bool
    {
        return $this->erreurs === [];
    }

    public function nombreErreurs(): int
    {
        return count($this->erreurs);
    }

    /** @return list<array{ligne: int, code: string, boutique_id: ?int, donnees: array<string, mixed>}> */
    public function lignes(): array
    {
        return $this->lignes;
    }

    /** @return list<array{ligne: ?int, colonne: ?string, motif: string}> */
    public function erreurs(): array
    {
        return $this->erreurs;
    }

    public function creations(): int
    {
        return count(array_filter($this->lignes, fn ($l) => $l['boutique_id'] === null));
    }

    public function misesAJour(): int
    {
        return count($this->lignes) - $this->creations();
    }

    /** @return array<string, int> */
    public function parNiveau(): array
    {
        return $this->parNiveau;
    }

    /** @return array<string, int> */
    public function parType(): array
    {
        ksort($this->parType);

        return $this->parType;
    }
}
