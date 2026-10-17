<?php

namespace App\Support\Tableau;

use App\Models\User;
use App\Services\Validation\SoumissionGroupee;
use Closure;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Tableau de données traité côté serveur (comportement de DataTables, sans dépendance) : nombre de lignes par page,
 * recherche globale sur les colonnes déclarées recherchables, filtres en listes déroulantes, tri par en-tête,
 * pagination, export Excel du résultat filtré. L'état tient dans l'adresse (?q=&tri=&page=&lignes=&<filtres>) ;
 * seule la page demandée est lue en base. Les colonnes non autorisées pour le profil sont écartées avant la requête.
 *
 *   return Tableau::pour(Site::query(), 'sites')->colonnes([...])->filtres([...])->triParDefaut('nom')
 *       ->export('Raaga_sites')->repondre($request, fn (ResultatTableau $t) => view('admin.sites.index', ['tableau' => $t]));
 */
final class Tableau
{
    public const LIGNES = [10, 25, 50, 100];

    public const LIGNES_DEFAUT = 25;

    /** Nombre maximal de lignes exportées en une fois. */
    public const EXPORT_MAX = 20000;

    /** @var list<Colonne> */
    private array $colonnes = [];

    /** @var list<Filtre> */
    private array $filtres = [];

    private ?string $triDefaut = null;

    private string $sensDefaut = 'asc';

    private ?string $nomExport = null;

    private ?Closure $departage = null;

    private int $lignesDefaut = self::LIGNES_DEFAUT;

    /** Nom des lignes dans « Affichage de 1 à 10 sur N … » (lignes, boutiques, imports…). */
    private ?string $unite = null;

    /** Bouton « Exporter » placé dans l'en-tête de la page (et non au-dessus du tableau). */
    private bool $exportEnTete = false;

    /** Entité des cases de soumission groupée (App\Services\Validation\SoumissionGroupee), si l'utilisateur en est l'auteur. */
    private ?string $selection = null;

    private function __construct(private readonly Builder $requete, public readonly string $id) {}

    public static function pour(Builder $requete, string $id = 'tableau'): self
    {
        return new self($requete, $id);
    }

    /** @param list<Colonne|null> $colonnes (null : colonne ignorée) */
    public function colonnes(array $colonnes): self
    {
        $this->colonnes = array_values(array_filter($colonnes));

        return $this;
    }

    /** @param list<Filtre|null> $filtres */
    public function filtres(array $filtres): self
    {
        $this->filtres = array_values(array_filter($filtres));

        return $this;
    }

    public function triParDefaut(string $cle, string $sens = 'asc'): self
    {
        $this->triDefaut = $cle;
        $this->sensDefaut = $sens === 'desc' ? 'desc' : 'asc';

        return $this;
    }

    public function lignesParDefaut(int $lignes): self
    {
        $this->lignesDefaut = in_array($lignes, self::LIGNES, true) ? $lignes : self::LIGNES_DEFAUT;

        return $this;
    }

    public function unite(string $unite): self
    {
        $this->unite = $unite;

        return $this;
    }

    public function exportEnTete(bool $enTete = true): self
    {
        $this->exportEnTete = $enTete;

        return $this;
    }

    /**
     * Cases à cocher de soumission groupée à validation (lignes brouillon ou rejetées) et filtre « À soumettre ».
     * Sans effet si l'utilisateur n'a pas le droit de soumettre cette entité. À appeler après filtres().
     */
    public function selection(string $entite, ?User $user): self
    {
        if (SoumissionGroupee::peut($user, $entite)) {
            $this->selection = $entite;
            $this->filtres[] = Filtre::make('a_soumettre', __('admin.soumission.a_soumettre', ['n' => '']), ['1' => '1'],
                fn (Builder $q) => SoumissionGroupee::aSoumettre($entite, $q))->masque();
        }

        return $this;
    }

    /** Ordre secondaire stable (identifiant par défaut), pour une pagination sans doublon. */
    public function departage(Closure $departage): self
    {
        $this->departage = $departage;

        return $this;
    }

    /** Applique une configuration seulement si la condition est vraie. */
    public function when(bool $condition, Closure $configuration): self
    {
        return $condition ? $configuration($this) : $this;
    }

    /** Active le bouton « Exporter » ; nom du fichier sans extension (la date est ajoutée). */
    public function export(string $nom): self
    {
        $this->nomExport = $nom;

        return $this;
    }

    /** Export Excel, réponse partielle (rechargement du seul tableau) ou page complète. */
    public function repondre(Request $request, Closure $vue): Response|StreamedResponse|View
    {
        if ($this->nomExport !== null && $request->query('export') === 'xlsx') {
            return $this->exporter($request);
        }
        $resultat = $this->traiter($request);
        // Demande de mise à jour du tableau (en-tête X-Tableau = identifiant du tableau) : le seul fragment des résultats,
        // confirmé par l'en-tête X-Tableau-Fragment ; sinon la page complète. Vary : un cache ne confond jamais les deux.
        $reponse = $request->header('X-Tableau') === $this->id
            ? response()->view('components.tableau-resultats', ['t' => $resultat])->header('X-Tableau-Fragment', $this->id)
            : response($vue($resultat));

        return $reponse->setVary('X-Tableau', false);
    }

    public function traiter(Request $request): ResultatTableau
    {
        $colonnes = $this->colonnesAutorisees();
        $etat = $this->etat($request, $colonnes);
        $total = (clone $this->requete)->count();
        $requete = $this->requeteFiltree($colonnes, $etat);
        $filtrees = (clone $requete)->count();

        $page = max(1, min($etat['page'], (int) ceil(max($filtrees, 1) / $etat['lignes'])));
        $lignes = $requete->forPage($page, $etat['lignes'])->get();

        return new ResultatTableau($this->id, $colonnes, $this->filtres, $etat, $lignes, $page, $total, $filtrees, $this->nomExport !== null, $request,
            $this->lignesDefaut, $this->unite, $this->exportEnTete, $this->selection,
            $this->selection ? SoumissionGroupee::aSoumettre($this->selection, clone $this->requete)->count() : 0);
    }

    /** Excel du résultat filtré et trié, avec les seules colonnes autorisées et exportables. */
    public function exporter(Request $request): StreamedResponse
    {
        $colonnes = array_values(array_filter($this->colonnesAutorisees(), fn (Colonne $c) => $c->estExportable()));
        $requete = $this->requeteFiltree($colonnes, $this->etat($request, $colonnes));

        $classeur = new Spreadsheet;
        $feuille = $classeur->getActiveSheet()->setTitle('Export');
        foreach ($colonnes as $i => $c) {
            $feuille->setCellValue(Coordinate::stringFromColumnIndex($i + 1).'1', $c->libelle);
        }
        $feuille->getStyle('1:1')->getFont()->setBold(true);
        $ligne = 2;
        foreach ($requete->limit(self::EXPORT_MAX)->lazy(500) as $modele) {
            foreach ($colonnes as $i => $c) {
                $valeur = $c->exporter($modele);
                $numerique = $c->estNumerique() && is_numeric($valeur);
                $feuille->setCellValueExplicit(Coordinate::stringFromColumnIndex($i + 1).$ligne, $numerique ? $valeur + 0 : (string) ($valeur ?? ''),
                    $numerique ? DataType::TYPE_NUMERIC : DataType::TYPE_STRING);
            }
            $ligne++;
        }
        foreach (array_keys($colonnes) as $i) {
            $feuille->getColumnDimension(Coordinate::stringFromColumnIndex($i + 1))->setAutoSize(true);
        }
        $nom = $this->nomExport.'-'.now()->format('Ymd-Hi').'.xlsx';

        return response()->streamDownload(fn () => (new Xlsx($classeur))->save('php://output'), $nom,
            ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']);
    }

    // ------------------------------------------------------------------ Interne

    /** @return list<Colonne> */
    private function colonnesAutorisees(): array
    {
        return array_values(array_filter($this->colonnes, fn (Colonne $c) => $c->autorisee()));
    }

    /**
     * État lu dans l'adresse, validé : valeurs inconnues ignorées.
     *
     * @param  list<Colonne>  $colonnes
     * @return array{q: string, tri: ?string, sens: string, page: int, lignes: int, filtres: array<string, string>}
     */
    private function etat(Request $request, array $colonnes): array
    {
        $tri = (string) $request->query('tri', '');
        $sens = str_starts_with($tri, '-') ? 'desc' : 'asc';
        $cle = ltrim($tri, '-');
        $triables = array_map(fn (Colonne $c) => $c->cle, array_filter($colonnes, fn (Colonne $c) => $c->estTriable()));
        if (! in_array($cle, $triables, true)) {
            [$cle, $sens] = in_array($this->triDefaut, $triables, true) ? [$this->triDefaut, $this->sensDefaut] : [null, 'asc'];
        }
        $lignes = (int) $request->query('lignes', $this->lignesDefaut);

        return [
            'q' => mb_substr(trim((string) (is_scalar($request->query('q')) ? $request->query('q') : '')), 0, 100),
            'tri' => $cle,
            'sens' => $sens,
            'page' => max(1, (int) $request->query('page', 1)),
            'lignes' => in_array($lignes, self::LIGNES, true) ? $lignes : $this->lignesDefaut,
            'filtres' => array_filter(array_combine(
                array_map(fn (Filtre $f) => $f->cle, $this->filtres),
                array_map(fn (Filtre $f) => $f->valeurDe($request->query($f->cle)), $this->filtres),
            ) ?: [], fn ($v) => $v !== null),
        ];
    }

    /** @param list<Colonne> $colonnes */
    private function requeteFiltree(array $colonnes, array $etat): Builder
    {
        $requete = clone $this->requete;
        foreach ($colonnes as $c) {
            $c->preparer($requete);
        }
        foreach ($this->filtres as $f) {
            if (isset($etat['filtres'][$f->cle])) {
                $f->appliquer($requete, $etat['filtres'][$f->cle]);
            }
        }
        $recherchables = array_filter($colonnes, fn (Colonne $c) => $c->estRecherchable());
        if ($etat['q'] !== '' && $recherchables) {
            $requete->where(function (Builder $q) use ($recherchables, $etat) {
                foreach ($recherchables as $c) {
                    $c->chercher($q, $etat['q']);
                }
            });
        }
        if ($etat['tri'] !== null) {
            foreach ($colonnes as $c) {
                if ($c->cle === $etat['tri']) {
                    $c->trier($requete, $etat['sens']);
                }
            }
        }
        $this->departage ? ($this->departage)($requete) : $requete->orderBy($requete->getModel()->getQualifiedKeyName());

        return $requete;
    }
}
