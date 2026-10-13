<?php

namespace App\Services\Sites;

/**
 * Résultat de l'analyse d'un fichier de sites : sites à créer ou à mettre à jour, capacités par site,
 * erreurs (onglet, ligne, colonne, motif). Rien n'est enregistré tant que l'import n'est pas confirmé.
 */
final class AnalyseImportSites
{
    /** @var array<string, array{ligne: int, site_id: ?int, donnees: array<string, mixed>}> code => site */
    private array $sites = [];

    /** @var array<string, list<array{ligne: int, donnees: array<string, mixed>}>> code du site => capacités */
    private array $capacites = [];

    /** @var list<array{onglet: ?string, ligne: ?int, colonne: ?string, motif: string}> */
    private array $erreurs = [];

    public function erreur(?string $onglet, ?int $ligne, ?string $colonne, string $motif, array $parametres = []): self
    {
        $this->erreurs[] = ['onglet' => $onglet, 'ligne' => $ligne, 'colonne' => $colonne,
            'motif' => strtr($motif, collect($parametres)->mapWithKeys(fn ($v, $k) => [':'.$k => $v])->all())];

        return $this;
    }

    /** @var list<string> codes lus dans l'onglet Sites, refusés ou non */
    private array $codesLus = [];

    public function noterCode(string $code): void
    {
        $this->codesLus[] = $code;
    }

    /** Site présent dans l'onglet Sites mais refusé : ses erreurs y sont déjà signalées. */
    public function siteRefuse(string $code): bool
    {
        return in_array($code, $this->codesLus, true) && ! isset($this->sites[$code]);
    }

    public function ajouterSite(string $code, int $ligne, ?int $siteId, array $donnees): void
    {
        $this->sites[$code] = ['ligne' => $ligne, 'site_id' => $siteId, 'donnees' => $donnees];
    }

    public function ajouterCapacite(string $code, int $ligne, array $donnees): void
    {
        $this->capacites[$code][] = ['ligne' => $ligne, 'donnees' => $donnees];
    }

    public function estValide(): bool
    {
        return $this->erreurs === [];
    }

    public function nombreErreurs(): int
    {
        return count($this->erreurs);
    }

    /** @return array<string, array{ligne: int, site_id: ?int, donnees: array<string, mixed>}> */
    public function sites(): array
    {
        return $this->sites;
    }

    /** @return array<string, array{ligne: int, site_id: ?int, donnees: array<string, mixed>}> */
    public function sitesACreer(): array
    {
        return array_filter($this->sites, fn ($s) => $s['site_id'] === null);
    }

    /** @return array<string, array{ligne: int, site_id: ?int, donnees: array<string, mixed>}> */
    public function sitesAMettreAJour(): array
    {
        return array_filter($this->sites, fn ($s) => $s['site_id'] !== null);
    }

    /** @return array<string, list<array{ligne: int, donnees: array<string, mixed>}>> */
    public function capacites(): array
    {
        return $this->capacites;
    }

    public function nombreCapacites(): int
    {
        return array_sum(array_map('count', $this->capacites));
    }

    /** @return list<array{onglet: ?string, ligne: ?int, colonne: ?string, motif: string}> */
    public function erreurs(): array
    {
        return $this->erreurs;
    }
}
