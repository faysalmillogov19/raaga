<?php

namespace App\Services\Boutiques;

use App\Models\Boutique;
use App\Models\GrilleTarifaire;
use App\Models\Niveau;
use App\Models\Site;
use App\Models\TypeBoutique;
use App\Models\Unite;
use Illuminate\Support\Collection;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataValidation;
use PhpOffice\PhpSpreadsheet\Reader\Xlsx as LecteurXlsx;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Protection;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx as EcrivainXlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * Grille tarifaire d'un site au format Excel, pour la remplir hors ligne puis l'importer :
 *  - onglet « Grille » (à compléter) : une ligne par niveau et type de boutique présents sur le site, avec le nombre de
 *    boutiques et leurs surfaces ; colonnes à remplir : unité du loyer, loyer mensuel, mois de caution, mois d'avance
 *    (valeurs en vigueur, sinon valeurs proposées) ; les colonnes d'identification sont verrouillées ;
 *  - onglet « Boutiques » (contrôle) : chaque boutique du site, avec son loyer, sa caution et son avance calculés par
 *    formule à partir de l'onglet « Grille », pour vérifier le résultat avant l'import ;
 *  - onglet « Mode d'emploi ».
 * L'import lit seulement l'onglet « Grille » ; il ne crée ni boutique ni combinaison, et remplace le brouillon de la
 * grille (qui doit ensuite être soumis au VALIDATEUR, comme une saisie à l'écran).
 */
class GrilleTarifaireExcel
{
    public const ONGLET = 'Grille';

    public const ONGLET_BOUTIQUES = 'Boutiques';

    /** Colonnes de l'onglet « Grille », dans l'ordre (libellés : admin.grilles.excel.colonnes). */
    public const COLONNES = ['niveau', 'niveau_libelle', 'type', 'type_libelle', 'boutiques', 'surfaces', 'unite', 'loyer', 'mois_caution', 'mois_avance', 'cle'];

    /** Colonnes à remplir (déverrouillées). */
    private const SAISIES = ['unite', 'loyer', 'mois_caution', 'mois_avance'];

    /** @param Collection<int, array{niveau: Niveau, type: TypeBoutique, nombre: int, surfaces: Collection}> $combinaisons */
    public function telecharger(Site $site, Collection $combinaisons): StreamedResponse
    {
        $classeur = $this->classeur($site, $combinaisons);
        $nom = __('admin.grilles.excel.nom_fichier', ['site' => $site->code ?? $site->id, 'date' => now()->format('Ymd')]);

        return response()->streamDownload(function () use ($classeur) {
            (new EcrivainXlsx($classeur))->save('php://output');
            $classeur->disconnectWorksheets();
        }, $nom, ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']);
    }

    /** @param Collection<int, array> $combinaisons */
    public function classeur(Site $site, Collection $combinaisons): Spreadsheet
    {
        $x = __('admin.grilles.excel');
        $classeur = new Spreadsheet;
        $grille = $classeur->getActiveSheet()->setTitle(self::ONGLET);
        $col = fn (string $cle) => Coordinate::stringFromColumnIndex(array_search($cle, self::COLONNES, true) + 1);
        $lignes = $site->grilleTarifaire?->lignes()->with('unite')->get()->keyBy(fn ($l) => GrilleTarifaire::cle($l->niveau, $l->type_boutique_id)) ?? collect();
        $unites = Unite::query()->utilisables()->get()->filter(fn (Unite $u) => $u->calcul() !== null)->keyBy('id');

        foreach (self::COLONNES as $i => $cle) {
            $grille->setCellValue([$i + 1, 1], $x['colonnes'][$cle]);
            $grille->getColumnDimensionByColumn($i + 1)->setWidth(match ($cle) {
                'niveau_libelle', 'type_libelle' => 30, 'surfaces' => 26, 'unite', 'loyer' => 18, default => 12,
            });
        }
        $derniereCol = $col('mois_avance');
        $grille->getStyle("A1:{$derniereCol}1")->getFont()->setBold(true);
        $grille->getStyle("A1:{$derniereCol}1")->getAlignment()->setWrapText(true);
        $grille->getRowDimension(1)->setRowHeight(32);
        $grille->freezePane('A2');

        foreach ($combinaisons->values() as $i => $c) {
            $n = $i + 2;
            $ligne = $lignes[GrilleTarifaire::cle($c['niveau']->code, $c['type']->id)] ?? null;
            $propose = GrilleTarifaire::valeursProposees($c['niveau']->code, $c['type']);
            $uniteId = $ligne?->unite_id ?? $propose['unite_id'];
            $valeurs = [
                'niveau' => $c['niveau']->code, 'niveau_libelle' => $c['niveau']->label(),
                'type' => $c['type']->code ?? (string) $c['type']->id, 'type_libelle' => $c['type']->libelle,
                'boutiques' => $c['nombre'],
                'surfaces' => $c['surfaces']->map(fn ($nb, $surface) => ($surface === '' ? $x['sans_surface'] : format_nombre((float) $surface, 2).' m²').' ('.$nb.')')->implode(', '),
                'unite' => $uniteId ? $unites->get($uniteId)?->code : null,
                'loyer' => $ligne?->loyer_m2, 'mois_caution' => $ligne?->mois_caution ?? $propose['mois_caution'], 'mois_avance' => $ligne?->mois_avance ?? $propose['mois_avance'],
            ];
            foreach ($valeurs as $cle => $valeur) {
                $grille->setCellValue($col($cle).$n, $valeur);
            }
            // Clé de recherche (onglet Boutiques) : niveau|type.
            $grille->setCellValue($col('cle').$n, "={$col('niveau')}{$n}&\"|\"&{$col('type')}{$n}");
        }
        $fin = max(2, $combinaisons->count() + 1);
        $grille->getColumnDimension($col('cle'))->setVisible(false);

        // Saisie : seules les colonnes à remplir sont modifiables (fond jaune pâle) ; unité en liste déroulante.
        foreach (self::SAISIES as $cle) {
            $plage = $col($cle)."2:{$col($cle)}{$fin}";
            $grille->getStyle($plage)->getProtection()->setLocked(Protection::PROTECTION_UNPROTECTED);
            $grille->getStyle($plage)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('FFF8D6');
        }
        $this->liste($grille, $col('unite')."2:{$col('unite')}{$fin}", $unites->pluck('code')->all(), $x['aide_unite']);
        foreach (['loyer' => [1, 100000000], 'mois_caution' => [0, 240], 'mois_avance' => [0, 240]] as $cle => [$min, $max]) {
            $this->entier($grille, $col($cle)."2:{$col($cle)}{$fin}", $min, $max, $x['aide_'.$cle]);
        }
        $grille->getProtection()->setSheet(true)->setFormatCells(false)->setFormatColumns(false)->setSort(false)->setAutoFilter(false);

        $this->boutiques($classeur, $site, $col);
        $this->modeEmploi($classeur, $unites);
        $classeur->setActiveSheetIndex(0);

        return $classeur;
    }

    /** Onglet de contrôle : montants de chaque boutique, recalculés par formule depuis l'onglet « Grille ». */
    private function boutiques(Spreadsheet $classeur, Site $site, \Closure $col): void
    {
        $x = __('admin.grilles.excel');
        $feuille = $classeur->createSheet()->setTitle(self::ONGLET_BOUTIQUES);
        $entetes = $x['boutiques'];
        foreach (array_values($entetes) as $i => $libelle) {
            $feuille->setCellValue([$i + 1, 1], $libelle);
            $feuille->getColumnDimensionByColumn($i + 1)->setWidth($i === 0 ? 22 : 15);
        }
        $feuille->getStyle('A1:I1')->getFont()->setBold(true);
        $feuille->getStyle('A1:I1')->getAlignment()->setWrapText(true);
        $feuille->freezePane('A2');

        $g = "'".self::ONGLET."'!";
        [$cNiv, $cType, $cUnite, $cLoyer, $cCaution, $cAvance, $cCle] = array_map($col, ['niveau', 'type', 'unite', 'loyer', 'mois_caution', 'mois_avance', 'cle']);
        $boutiques = Boutique::query()->where('site_id', $site->id)->whereNotNull('niveau')->with('type')
            ->get()->filter(fn (Boutique $b) => $b->statut->value !== 'RETIREE')->sortBy('code', SORT_NATURAL)->values();
        foreach ($boutiques as $i => $b) {
            $n = $i + 2;
            $feuille->fromArray([$b->code, $b->niveau, $b->bloc, $b->numero, $b->type?->code ?? (string) $b->type_boutique_id, $b->surface !== null ? (float) $b->surface : null], null, "A{$n}");
            // Colonne J (masquée) : ligne de la grille pour ce niveau et ce type, 0 si absente.
            $feuille->setCellValue("J{$n}", "=IFERROR(MATCH(\$B{$n}&\"|\"&\$E{$n},{$g}\${$cCle}:\${$cCle},0),0)");
            $trouve = fn (string $c) => "INDEX({$g}\${$c}:\${$c},\$J{$n})";
            // Loyer mensuel : au m² = surface × loyer ; à l'unité ou en redevance = loyer tel quel ; arrondi au franc.
            $feuille->setCellValue("G{$n}", "=IF(\$J{$n}=0,\"\",IF({$trouve($cLoyer)}=\"\",\"\",ROUND(IF({$trouve($cUnite)}=\"M2\",\$F{$n}*{$trouve($cLoyer)},{$trouve($cLoyer)}),0)))");
            $feuille->setCellValue("H{$n}", "=IF(G{$n}=\"\",\"\",ROUND(G{$n}*{$trouve($cCaution)},0))");
            $feuille->setCellValue("I{$n}", "=IF(G{$n}=\"\",\"\",ROUND(G{$n}*{$trouve($cAvance)},0))");
        }
        $feuille->getStyle('G2:I'.max(2, $boutiques->count() + 1))->getNumberFormat()->setFormatCode('#,##0');
        $feuille->setAutoFilter('A1:I'.max(2, $boutiques->count() + 1));
        $feuille->getColumnDimension('J')->setVisible(false);
    }

    private function modeEmploi(Spreadsheet $classeur, Collection $unites): void
    {
        $x = __('admin.grilles.excel');
        $feuille = $classeur->createSheet()->setTitle($x['onglet_mode_emploi']);
        $feuille->getColumnDimension('A')->setWidth(120);
        $lignes = [...$x['mode_emploi'], '', $x['unites_titre'], ...$unites->map(fn (Unite $u) => $u->code.' : '.$u->libelle)->values()->all()];
        foreach ($lignes as $i => $texte) {
            $feuille->setCellValue('A'.($i + 1), $texte);
            $feuille->getStyle('A'.($i + 1))->getAlignment()->setWrapText(true);
        }
        $feuille->getStyle('A1')->getFont()->setBold(true)->setSize(14);
    }

    private function liste(Worksheet $feuille, string $plage, array $valeurs, string $aide): void
    {
        $v = $feuille->getDataValidation(explode(':', $plage)[0]);
        $v->setType(DataValidation::TYPE_LIST)->setAllowBlank(true)->setShowDropDown(true)->setShowErrorMessage(true)
            ->setShowInputMessage(true)->setPrompt($aide)->setFormula1('"'.implode(',', $valeurs).'"');
        $v->setSqref($plage);
    }

    private function entier(Worksheet $feuille, string $plage, int $min, int $max, string $aide): void
    {
        $v = $feuille->getDataValidation(explode(':', $plage)[0]);
        $v->setType(DataValidation::TYPE_WHOLE)->setOperator(DataValidation::OPERATOR_BETWEEN)->setAllowBlank(true)
            ->setShowErrorMessage(true)->setShowInputMessage(true)->setPrompt($aide)->setFormula1((string) $min)->setFormula2((string) $max);
        $v->setSqref($plage);
    }

    // ------------------------------------------------------------------ Import

    /**
     * Lecture et contrôle de l'onglet « Grille », sans écriture. Rend les saisies au format du formulaire
     * (lignes[niveau][type_id] = unite_id, loyer_m2, mois_caution, mois_avance) et la liste des erreurs
     * (ligne du fichier, colonne, message). Une ligne sans loyer ne crée pas de ligne de grille.
     *
     * @param  Collection<int, array>  $combinaisons
     * @return array{saisies: array<string, array<int, array<string, int>>>, erreurs: list<array{ligne: ?int, colonne: ?string, message: string}>, lignes: int}
     */
    public function analyser(string $chemin, Collection $combinaisons): array
    {
        $x = __('admin.grilles.excel');
        $erreurs = [];
        $erreur = function (?int $ligne, ?string $cle, string $message) use (&$erreurs, $x) {
            $erreurs[] = ['ligne' => $ligne, 'colonne' => $cle ? $x['colonnes'][$cle] : null, 'message' => $message];
        };

        try {
            $lecteur = new LecteurXlsx;
            $lecteur->setReadDataOnly(true);
            $feuille = null;
            // Onglet absent : signalé comme tel (et non comme un fichier illisible).
            if (in_array(self::ONGLET, $lecteur->listWorksheetNames($chemin), true)) {
                $lecteur->setLoadSheetsOnly([self::ONGLET]);
                $feuille = $lecteur->load($chemin)->getSheetByName(self::ONGLET);
            }
        } catch (Throwable) {
            $feuille = null;
            $erreur(null, null, $x['erreurs']['illisible']);
        }
        if ($feuille === null) {
            if ($erreurs === []) {
                $erreur(null, null, $x['erreurs']['onglet']);
            }

            return ['saisies' => [], 'erreurs' => $erreurs, 'lignes' => 0];
        }

        // En-tête repéré par les libellés, quel que soit l'ordre des colonnes.
        $positions = [];
        $max = Coordinate::columnIndexFromString($feuille->getHighestDataColumn());
        $libelles = array_map(fn ($l) => mb_strtolower(trim($l)), $x['colonnes']);
        for ($i = 1; $i <= $max; $i++) {
            $cle = array_search(mb_strtolower(trim((string) $feuille->getCell([$i, 1])->getValue())), $libelles, true);
            if ($cle !== false) {
                $positions[$cle] = $i;
            }
        }
        foreach (['niveau', 'type', ...self::SAISIES] as $cle) {
            if (! isset($positions[$cle])) {
                $erreur(1, $cle, $x['erreurs']['colonne_absente']);
            }
        }
        if ($erreurs !== []) {
            return ['saisies' => [], 'erreurs' => $erreurs, 'lignes' => 0];
        }

        $connues = $combinaisons->keyBy(fn ($c) => mb_strtoupper($c['niveau']->code).'|'.mb_strtoupper($c['type']->code ?? (string) $c['type']->id));
        $unites = Unite::query()->utilisables()->get()->filter(fn (Unite $u) => $u->calcul() !== null);
        $parCode = $unites->keyBy(fn (Unite $u) => mb_strtoupper($u->code));
        $parLibelle = $unites->keyBy(fn (Unite $u) => mb_strtolower($u->libelle));
        $saisies = [];
        $vues = [];
        $nombre = 0;
        $valeur = fn (string $cle, int $ligne) => isset($positions[$cle]) ? $feuille->getCell([$positions[$cle], $ligne])->getCalculatedValue() : null;
        $entier = fn ($v) => is_numeric($v) && (float) $v == (int) $v ? (int) $v : null;

        for ($ligne = 2; $ligne <= $feuille->getHighestDataRow(); $ligne++) {
            $niveau = mb_strtoupper(trim((string) $valeur('niveau', $ligne)));
            $type = mb_strtoupper(trim((string) $valeur('type', $ligne)));
            $brut = array_map(fn ($c) => $valeur($c, $ligne), array_combine(self::SAISIES, self::SAISIES));
            if ($niveau === '' && $type === '' && array_filter($brut, fn ($v) => $v !== null && $v !== '') === []) {
                continue; // ligne vide
            }
            $c = $connues[$niveau.'|'.$type] ?? null;
            if (! $c) {
                $erreur($ligne, 'type', __('admin.grilles.excel.erreurs.combinaison', ['niveau' => $niveau, 'type' => $type]));

                continue;
            }
            if (isset($vues[$niveau.'|'.$type])) {
                $erreur($ligne, 'type', __('admin.grilles.excel.erreurs.doublon', ['ligne' => $vues[$niveau.'|'.$type]]));

                continue;
            }
            $vues[$niveau.'|'.$type] = $ligne;
            if ($brut['loyer'] === null || $brut['loyer'] === '') {
                continue; // pas de loyer : pas de ligne de grille
            }
            $loyer = $entier($brut['loyer']);
            if ($loyer === null || $loyer < 1 || $loyer > 100000000) {
                $erreur($ligne, 'loyer', $x['erreurs']['loyer']);
            }
            $u = $parCode[mb_strtoupper(trim((string) $brut['unite']))] ?? $parLibelle[mb_strtolower(trim((string) $brut['unite']))] ?? null;
            if (! $u) {
                $erreur($ligne, 'unite', __('admin.grilles.excel.erreurs.unite', ['unites' => $parCode->keys()->implode(', ')]));
            }
            foreach (['mois_caution', 'mois_avance'] as $cle) {
                $m = $entier($brut[$cle]);
                if ($m === null || $m < 0 || $m > 240) {
                    $erreur($ligne, $cle, $x['erreurs']['mois']);
                }
            }
            if ($loyer !== null && $u) {
                $saisies[$c['niveau']->code][$c['type']->id] = ['unite_id' => $u->id, 'loyer_m2' => $loyer,
                    'mois_caution' => (int) $entier($brut['mois_caution']), 'mois_avance' => (int) $entier($brut['mois_avance'])];
                $nombre++;
            }
        }
        if ($erreurs === [] && $nombre === 0) {
            $erreur(null, null, $x['erreurs']['vide']);
        }

        return ['saisies' => $erreurs === [] ? $saisies : [], 'erreurs' => $erreurs, 'lignes' => $nombre];
    }
}
