<?php

namespace App\Services\Boutiques;

use App\Enums\NiveauBoutique;
use App\Enums\StatutBoutique;
use App\Models\Boutique;
use App\Models\Site;
use App\Models\TypeBoutique;
use App\Models\User;
use App\Services\Audit\Journal;
use App\Services\Backoffice\RegleGestionException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataValidation;
use PhpOffice\PhpSpreadsheet\Reader\Xlsx as LecteurXlsx;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx as EcrivainXlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * Référentiel des boutiques d'un site au format Excel (.xlsx, onglet « Locaux ») : modèle vierge, export, et import
 * en deux temps (analyse sans écriture, puis enregistrement tout ou rien). Les colonnes Code et Contrôle sont des
 * aides de saisie (formules) : elles sont ignorées à l'import, le code étant toujours recalculé.
 */
class ReferentielExcel
{
    public const ONGLET = 'Locaux';

    /** Colonnes dans l'ordre du fichier (libellés dans lang/fr/admin.php, admin.sites.excel.colonnes). */
    public const COLONNES = ['code', 'site', 'niveau', 'bloc', 'numero', 'type', 'surface', 'planche', 'observations', 'controle'];

    /** Colonnes ignorées à l'import. */
    private const CALCULEES = ['code', 'controle'];

    /** Colonnes obligatoires dans l'en-tête et dans chaque ligne. */
    private const OBLIGATOIRES = ['site', 'niveau', 'bloc', 'numero', 'type', 'surface'];

    public function __construct(private readonly Journal $journal) {}

    // ------------------------------------------------------------------ Modèle et export

    /** Modèle vierge (lignes préparées avec les formules) ou export des boutiques du site. */
    public function telecharger(Site $site, bool $avecBoutiques): StreamedResponse
    {
        $boutiques = $avecBoutiques ? $site->boutiques()->with('type')->get() : collect();
        $classeur = $this->classeur($boutiques);
        $nom = __($avecBoutiques ? 'admin.sites.excel.nom_export' : 'admin.sites.excel.nom_modele', ['site' => $site->code ?? $site->id, 'date' => now()->format('Ymd')]);

        return response()->streamDownload(function () use ($classeur) {
            (new EcrivainXlsx($classeur))->save('php://output');
            $classeur->disconnectWorksheets();
        }, $nom, ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']);
    }

    /** @param Collection<int, Boutique> $boutiques */
    private function classeur(Collection $boutiques): Spreadsheet
    {
        $classeur = new Spreadsheet;
        $feuille = $classeur->getActiveSheet()->setTitle(self::ONGLET);
        $libelles = __('admin.sites.excel.colonnes');
        $col = fn (string $cle) => Coordinate::stringFromColumnIndex(array_search($cle, self::COLONNES, true) + 1);

        foreach (self::COLONNES as $i => $cle) {
            $feuille->setCellValue([$i + 1, 1], $libelles[$cle]);
            $feuille->getColumnDimensionByColumn($i + 1)->setWidth(in_array($cle, ['code', 'observations'], true) ? 22 : 14);
        }
        $feuille->getStyle('A1:'.$col('controle').'1')->getFont()->setBold(true);
        $feuille->freezePane('A2');

        foreach ($boutiques->values() as $i => $b) {
            $ligne = $i + 2;
            $valeurs = [
                'site' => $b->site?->code, 'niveau' => $b->niveau?->value, 'bloc' => $b->bloc, 'numero' => $b->code ? (int) $b->numero : $b->numero,
                'type' => $b->type->code ?? $b->type->libelle, 'surface' => $b->surface !== null ? (float) $b->surface : null,
                'planche' => $b->planche, 'observations' => $b->observations,
            ];
            foreach ($valeurs as $cle => $valeur) {
                $feuille->setCellValue($col($cle).$ligne, $valeur);
            }
        }

        // Formules d'aide : code calculé et contrôle des doublons, sur les lignes remplies et les suivantes.
        $derniere = $boutiques->count() + 1 + (int) config('raaga.referentiel_boutiques.lignes_modele');
        $controle = __('admin.sites.excel.controle');
        [$s, $n, $bl, $nu, $c] = [$col('site'), $col('niveau'), $col('bloc'), $col('numero'), $col('code')];
        for ($ligne = 2; $ligne <= $derniere; $ligne++) {
            $feuille->setCellValue($c.$ligne, "=IF(OR({$s}{$ligne}=\"\",{$n}{$ligne}=\"\",{$bl}{$ligne}=\"\",{$nu}{$ligne}=\"\"),\"\","
                ."UPPER({$s}{$ligne})&\"-\"&UPPER({$n}{$ligne})&\"-\"&UPPER({$bl}{$ligne})&\"-\"&TEXT({$nu}{$ligne},\"000\"))");
            $feuille->setCellValue($col('controle').$ligne, "=IF({$c}{$ligne}=\"\",\"\",IF(COUNTIF({$c}\$2:{$c}\${$derniere},{$c}{$ligne})>1,\""
                .$controle['doublon'].'","'.$controle['ok'].'"))');
        }

        // Listes déroulantes : niveaux et codes des types.
        $this->liste($feuille, $n."2:{$n}{$derniere}", array_map(fn (NiveauBoutique $x) => $x->value, NiveauBoutique::cases()));
        $codesTypes = TypeBoutique::query()->whereNotNull('code')->orderBy('ordre')->pluck('code')->all();
        if ($codesTypes !== []) {
            $this->liste($feuille, $col('type')."2:{$col('type')}{$derniere}", $codesTypes);
        }

        return $classeur;
    }

    private function liste($feuille, string $plage, array $valeurs): void
    {
        // Une seule règle, posée sur la première cellule puis étendue à la plage.
        $validation = $feuille->getDataValidation(explode(':', $plage)[0]);
        $validation->setType(DataValidation::TYPE_LIST)->setAllowBlank(true)->setShowDropDown(true)
            ->setShowErrorMessage(true)->setFormula1('"'.implode(',', $valeurs).'"');
        $validation->setSqref($plage);
    }

    // ------------------------------------------------------------------ Import

    /** Lecture et contrôle du fichier, sans aucune écriture en base. */
    public function analyser(Site $site, string $chemin): AnalyseImport
    {
        $analyse = new AnalyseImport;
        $e = __('admin.sites.excel.erreurs');
        $libelles = __('admin.sites.excel.colonnes');

        if ($site->code === null) {
            return $analyse->erreur(null, null, __('admin.sites.regles.sans_code_site'));
        }

        try {
            $lecteur = new LecteurXlsx;
            $lecteur->setReadDataOnly(true);
            $lecteur->setLoadSheetsOnly([self::ONGLET]);
            $classeur = $lecteur->load($chemin);
            $feuille = $classeur->getSheetByName(self::ONGLET);
        } catch (Throwable) {
            return $analyse->erreur(null, null, $e['illisible']);
        }
        if ($feuille === null) {
            return $analyse->erreur(null, null, $e['onglet'], ['onglet' => self::ONGLET]);
        }

        // En-tête : les colonnes sont repérées par leur libellé, quel que soit leur ordre.
        $positions = [];
        $maxColonne = Coordinate::columnIndexFromString($feuille->getHighestDataColumn());
        for ($i = 1; $i <= $maxColonne; $i++) {
            $cle = array_search(mb_strtolower(trim((string) $feuille->getCell([$i, 1])->getValue())), array_map('mb_strtolower', $libelles), true);
            if ($cle !== false && ! in_array($cle, self::CALCULEES, true)) {
                $positions[$cle] = $i;
            }
        }
        foreach (self::OBLIGATOIRES as $cle) {
            if (! isset($positions[$cle])) {
                $analyse->erreur(1, $libelles[$cle], $e['colonne_absente']);
            }
        }
        $derniere = $feuille->getHighestDataRow();
        if ($derniere - 1 > (int) config('raaga.referentiel_boutiques.lignes_max')) {
            $analyse->erreur(null, null, $e['trop_de_lignes'], ['max' => config('raaga.referentiel_boutiques.lignes_max')]);
        }
        if (! $analyse->estValide()) {
            return $analyse;
        }

        $types = TypeBoutique::query()->get();
        $vus = [];
        for ($ligne = 2; $ligne <= $derniere; $ligne++) {
            $v = [];
            foreach ($positions as $cle => $i) {
                $brut = $feuille->getCell([$i, $ligne])->getValue();
                $v[$cle] = is_string($brut) ? trim($brut) : $brut;
            }
            if (collect($v)->every(fn ($x) => $x === null || $x === '')) {
                continue; // Ligne vide.
            }

            $erreurs = $analyse->nombreErreurs();
            $err = fn (string $cle, string $motif, array $p = []) => $analyse->erreur($ligne, $libelles[$cle], $motif, $p);

            foreach (self::OBLIGATOIRES as $cle) {
                if ($v[$cle] === null || $v[$cle] === '') {
                    $err($cle, $e['obligatoire']);
                }
            }

            $siteFichier = mb_strtoupper((string) $v['site']);
            if ($siteFichier !== '' && $siteFichier !== $site->code) {
                $err('site', $e['site'], ['attendu' => $site->code, 'lu' => $siteFichier]);
            }
            $niveau = NiveauBoutique::tryFrom(mb_strtoupper((string) $v['niveau']));
            if ($v['niveau'] !== null && $v['niveau'] !== '' && $niveau === null) {
                $err('niveau', $e['valeur'], ['valeurs' => implode(', ', array_map(fn ($x) => $x->value, NiveauBoutique::cases()))]);
            }
            $bloc = mb_strtoupper((string) $v['bloc']);
            if ($bloc !== '' && ! preg_match(Boutique::FORMAT_BLOC, $bloc)) {
                $err('bloc', $e['bloc']);
            }
            $numero = $this->entier($v['numero']);
            $max = (int) str_repeat('9', Boutique::CHIFFRES_NUMERO);
            if ($v['numero'] !== null && $v['numero'] !== '' && ($numero === null || $numero < 1 || $numero > $max)) {
                $err('numero', $e['numero'], ['max' => $max]);
            }
            $type = $v['type'] === null || $v['type'] === '' ? null : $types->first(fn (TypeBoutique $t) => ($t->code !== null && mb_strtoupper((string) $v['type']) === $t->code)
                || mb_strtolower((string) $v['type']) === mb_strtolower($t->libelle));
            if ($v['type'] !== null && $v['type'] !== '' && $type === null) {
                $err('type', $e['type'], ['valeur' => $v['type']]);
            }
            $surface = is_numeric($s = str_replace(',', '.', (string) $v['surface'])) ? (float) $s : null;
            if ($v['surface'] !== null && $v['surface'] !== '' && ($surface === null || $surface <= 0 || $surface > 99999)) {
                $err('surface', $e['surface']);
            }
            foreach (['planche' => 60, 'observations' => 500] as $cle => $longueur) {
                if (isset($v[$cle]) && mb_strlen((string) $v[$cle]) > $longueur) {
                    $err($cle, $e['longueur'], ['max' => $longueur]);
                }
            }

            if ($analyse->nombreErreurs() > $erreurs) {
                continue;
            }

            $code = Boutique::composerCode($site->code, $niveau->value, $bloc, (string) $numero);
            if (isset($vus[$code])) {
                $err('numero', $e['doublon_fichier'], ['code' => $code, 'ligne' => $vus[$code]]);

                continue;
            }
            $vus[$code] = $ligne;

            $existante = Boutique::withTrashed()->where('code', $code)->first();
            if ($existante?->trashed()) {
                $err('numero', $e['supprimee'], ['code' => $code]);

                continue;
            }
            if ($existante && ($existante->site_id !== $site->id || $existante->statut->verrouillee())) {
                $err('numero', $e['engagee'], ['code' => $code, 'statut' => mb_strtolower($existante->statut->label())]);

                continue;
            }

            $analyse->ajouter($ligne, $code, $existante?->id, [
                'niveau' => $niveau, 'bloc' => $bloc, 'numero' => str_pad((string) $numero, Boutique::CHIFFRES_NUMERO, '0', STR_PAD_LEFT),
                'type_boutique_id' => $type->id, 'surface' => $surface,
                'planche' => ($v['planche'] ?? '') === '' ? null : (string) $v['planche'],
                'observations' => ($v['observations'] ?? '') === '' ? null : (string) $v['observations'],
            ], $niveau->label(), $type->libelle);
        }
        $classeur->disconnectWorksheets();

        if ($analyse->lignes() === [] && $analyse->estValide()) {
            $analyse->erreur(null, null, $e['vide']);
        }

        return $analyse;
    }

    /**
     * Enregistrement tout ou rien d'une analyse sans erreur. Une boutique existante (même code) n'est mise à jour
     * que si elle est libre ; c'est vérifié de nouveau dans la transaction.
     *
     * @return array{creees: int, mises_a_jour: int}
     *
     * @throws RegleGestionException
     */
    public function importer(Site $site, AnalyseImport $analyse, User $auteur, string $nomFichier, string $empreinte): array
    {
        if (! $analyse->estValide()) {
            throw new RegleGestionException(__('admin.sites.excel.erreurs.non_valide'));
        }

        return DB::transaction(function () use ($site, $analyse, $auteur, $nomFichier, $empreinte) {
            $site = Site::query()->lockForUpdate()->findOrFail($site->id);
            if (! $site->estModifiable()) {
                throw new RegleGestionException(__('admin.parametrage.regles.non_modifiable'));
            }

            $compte = ['creees' => 0, 'mises_a_jour' => 0];
            foreach ($analyse->lignes() as $l) {
                if ($l['boutique_id'] === null) {
                    $site->boutiques()->create([...$l['donnees'], 'statut' => StatutBoutique::Disponible]);
                    $compte['creees']++;

                    continue;
                }
                $boutique = Boutique::query()->lockForUpdate()->findOrFail($l['boutique_id']);
                if ($boutique->statut->verrouillee()) {
                    throw new RegleGestionException(__('admin.sites.excel.erreurs.engagee', ['code' => $l['code'], 'statut' => mb_strtolower($boutique->statut->label())]));
                }
                // Le code ne change pas : seuls le type, la surface, la planche et les observations sont mis à jour.
                $boutique->fill(collect($l['donnees'])->except(['niveau', 'bloc', 'numero'])->all())->save();
                $compte['mises_a_jour']++;
            }

            $this->journal->ecrire($auteur, 'boutiques.importees', 'Site', $site->id, null, [
                'fichier' => $nomFichier, 'empreinte' => $empreinte, ...$compte,
            ]);

            return $compte;
        });
    }

    private function entier(mixed $valeur): ?int
    {
        if (is_int($valeur)) {
            return $valeur;
        }
        if (is_float($valeur)) {
            return floor($valeur) === $valeur ? (int) $valeur : null;
        }

        return is_string($valeur) && preg_match('/^\d+$/', $valeur) ? (int) $valeur : null;
    }
}
