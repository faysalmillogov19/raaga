<?php

namespace App\Services\Boutiques;

/**
 * Résultat de l'analyse d'un fichier « Locaux » : lignes valides (avec leur site), erreurs (ligne, colonne, motif)
 * qui bloquent la confirmation, avertissements qui ne la bloquent pas, et totaux.
 */
final class AnalyseImport
{
    /** @var list<array{ligne: int, code: string, site_id: int, boutique_id: ?int, donnees: array<string, mixed>}> */
    private array $lignes = [];

    /** @var list<string> */
    private array $avertissements = [];

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

    public function avertir(string $message, array $parametres = []): self
    {
        $this->avertissements[] = strtr($message, collect($parametres)->mapWithKeys(fn ($v, $k) => [':'.$k => $v])->all());

        return $this;
    }

    /** @return list<string> */
    public function avertissements(): array
    {
        return $this->avertissements;
    }

    public function ajouter(int $ligne, string $code, int $siteId, ?int $boutiqueId, array $donnees, string $niveau, string $type): void
    {
        $this->lignes[] = ['ligne' => $ligne, 'code' => $code, 'site_id' => $siteId, 'boutique_id' => $boutiqueId, 'donnees' => $donnees];
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

    /** @return list<array{ligne: int, code: string, site_id: int, boutique_id: ?int, donnees: array<string, mixed>}> */
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
