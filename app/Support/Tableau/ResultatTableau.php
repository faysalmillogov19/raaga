<?php

namespace App\Support\Tableau;

use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/** Page de résultats d'un tableau de données, prête pour l'affichage, et adresses qui conservent l'état. */
final class ResultatTableau
{
    /**
     * @param  list<Colonne>  $colonnes  colonnes autorisées
     * @param  list<Filtre>  $filtres
     * @param  array{q: string, tri: ?string, sens: string, page: int, lignes: int, filtres: array<string, string>}  $etat
     */
    public function __construct(
        public readonly string $id,
        public readonly array $colonnes,
        public readonly array $filtres,
        public readonly array $etat,
        public readonly Collection $lignes,
        public readonly int $page,
        public readonly int $total,
        public readonly int $filtrees,
        public readonly bool $exportable,
        private readonly Request $request,
    ) {}

    public function derniere(): int
    {
        return max(1, (int) ceil($this->filtrees / $this->etat['lignes']));
    }

    public function premiereLigne(): int
    {
        return $this->filtrees === 0 ? 0 : ($this->page - 1) * $this->etat['lignes'] + 1;
    }

    public function derniereLigne(): int
    {
        return min($this->filtrees, $this->page * $this->etat['lignes']);
    }

    public function estFiltre(): bool
    {
        return $this->etat['q'] !== '' || $this->etat['filtres'] !== [];
    }

    public function aDesRecherchables(): bool
    {
        return collect($this->colonnes)->contains(fn (Colonne $c) => $c->estRecherchable());
    }

    /** Pages affichées : 1 … autour de la page courante … dernière (null = points de suspension). @return list<?int> */
    public function pages(): array
    {
        $derniere = $this->derniere();
        $voisines = range(max(1, $this->page - 1), min($derniere, $this->page + 1));
        $pages = array_unique([1, ...$voisines, $derniere]);
        sort($pages);
        $resultat = [];
        foreach ($pages as $i => $p) {
            if ($i > 0 && $p - $pages[$i - 1] > 1) {
                $resultat[] = $p - $pages[$i - 1] === 2 ? $p - 1 : null;
            }
            $resultat[] = $p;
        }

        return $resultat;
    }

    /** Paramètres de l'adresse courante (valeurs par défaut omises). @return array<string, string|int> */
    public function parametres(array $changements = []): array
    {
        $p = [
            'q' => $this->etat['q'],
            'tri' => $this->etat['tri'] === null ? '' : ($this->etat['sens'] === 'desc' ? '-' : '').$this->etat['tri'],
            'lignes' => $this->etat['lignes'] === Tableau::LIGNES_DEFAUT ? '' : $this->etat['lignes'],
            'page' => $this->page > 1 ? $this->page : '',
            ...$this->etat['filtres'],
        ];
        $p = array_merge($p, $changements);

        return array_filter($p, fn ($v) => $v !== '' && $v !== null && $v !== 1);
    }

    public function url(array $changements = []): string
    {
        $parametres = $this->parametres($changements);

        return $this->request->url().($parametres ? '?'.http_build_query($parametres) : '');
    }

    public function urlPage(int $page): string
    {
        return $this->url(['page' => $page]);
    }

    /** Clic sur un en-tête : croissant, puis décroissant. */
    public function urlTri(Colonne $colonne): string
    {
        $desc = $this->etat['tri'] === $colonne->cle && $this->etat['sens'] === 'asc';

        return $this->url(['tri' => ($desc ? '-' : '').$colonne->cle, 'page' => '']);
    }

    public function ariaSort(Colonne $colonne): string
    {
        return $this->etat['tri'] !== $colonne->cle ? 'none' : ($this->etat['sens'] === 'asc' ? 'ascending' : 'descending');
    }

    public function urlExport(): string
    {
        return $this->url(['export' => 'xlsx', 'page' => '']);
    }
}
