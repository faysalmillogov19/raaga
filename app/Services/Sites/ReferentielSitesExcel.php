<?php

namespace App\Services\Sites;

use App\Enums\PhaseSite;
use App\Enums\StatutSite;
use App\Models\Niveau;
use App\Models\Site;
use App\Models\TypeBoutique;
use App\Models\User;
use App\Models\ZoneSite;
use App\Services\Audit\Journal;
use App\Services\Backoffice\RegleGestionException;
use App\Support\FicheSite;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataValidation;
use PhpOffice\PhpSpreadsheet\Reader\Xlsx as LecteurXlsx;
use PhpOffice\PhpSpreadsheet\Shared\Date as DateExcel;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx as EcrivainXlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * Sites et capacités prévues au format du fichier « Raaga_import_sites.xlsx » (onglets « Sites » et « Capacités »,
 * en-têtes en ligne 1) : modèle, export, et import en deux temps (analyse sans écriture, puis enregistrement tout
 * ou rien). Les colonnes calculées (complément, part en %, surface retenue, contrôle) sont des formules d'aide :
 * elles sont ignorées à l'import. Les sites sont créés en brouillon ; un site soumis ou confirmé n'est jamais modifié.
 */
class ReferentielSitesExcel
{
    public const ONGLET_SITES = 'Sites';

    public const ONGLET_CAPACITES = 'Capacités';

    /** Colonnes dans l'ordre du fichier (libellés : lang/fr/admin.php, admin.sites.import.colonnes_*). */
    public const COLONNES_SITES = ['code', 'nom', 'ville', 'arrondissement', 'adresse', 'latitude', 'longitude', 'maitre_ouvrage', 'maitre_oeuvre',
        'phase', 'livraison_prevue', 'reference_plans', 'version_plans', 'source_donnees', 'controle'];

    public const COLONNES_CAPACITES = ['site', 'niveau', 'zone', 'type', 'nombre_prevu', 'surface_unitaire', 'surface_totale', 'surface_retenue',
        'source', 'observations', 'controle'];

    private const CALCULEES = ['surface_retenue', 'controle'];

    /** Fond des en-têtes de colonnes calculées (gris-vert, comme le fichier de référence). */
    private const FOND_CALCULE = 'DCE5DF';

    public function __construct(private readonly Journal $journal) {}

    // ------------------------------------------------------------------ Modèle et export

    public function telecharger(bool $avecDonnees): StreamedResponse
    {
        $classeur = $this->classeur($avecDonnees ? Site::query()->with('capacites.type')->orderBy('code')->get() : collect());
        $nom = __($avecDonnees ? 'admin.sites.import.nom_export' : 'admin.sites.import.nom_modele', ['date' => now()->format('Ymd')]);

        return response()->streamDownload(function () use ($classeur) {
            (new EcrivainXlsx($classeur))->save('php://output');
            $classeur->disconnectWorksheets();
        }, $nom, ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']);
    }

    /** @param Collection<int, Site> $sites */
    private function classeur(Collection $sites): Spreadsheet
    {
        $config = config('raaga.referentiel_sites');
        $classeur = new Spreadsheet;
        $s = $classeur->getActiveSheet()->setTitle(self::ONGLET_SITES);
        $c = $classeur->createSheet()->setTitle(self::ONGLET_CAPACITES);
        $this->entetes($s, self::COLONNES_SITES, __('admin.sites.import.colonnes_sites'));
        $this->entetes($c, self::COLONNES_CAPACITES, __('admin.sites.import.colonnes_capacites'));
        $colS = fn (string $cle) => Coordinate::stringFromColumnIndex(array_search($cle, self::COLONNES_SITES, true) + 1);
        $colC = fn (string $cle) => Coordinate::stringFromColumnIndex(array_search($cle, self::COLONNES_CAPACITES, true) + 1);

        // Données (export).
        $ligneC = 2;
        foreach ($sites->values() as $i => $site) {
            $valeurs = [...$site->only(['code', 'nom', 'arrondissement', 'adresse', 'maitre_ouvrage', 'maitre_oeuvre',
                'reference_plans', 'version_plans', 'source_donnees']),
                'ville' => $site->localite(), 'latitude' => $site->latitude !== null ? (float) $site->latitude : null,
                'longitude' => $site->longitude !== null ? (float) $site->longitude : null, 'phase' => $site->phase?->label(),
                'livraison_prevue' => $site->livraison_prevue ? DateExcel::PHPToExcel($site->livraison_prevue) : null];
            foreach ($valeurs as $cle => $valeur) {
                $s->setCellValue($colS($cle).($i + 2), $valeur);
            }
            foreach ($site->capacites as $cap) {
                foreach (['site' => $site->code, 'niveau' => $cap->niveau, 'zone' => $cap->zone, 'type' => $cap->type->code ?? $cap->type->libelle,
                    'nombre_prevu' => $cap->nombre_prevu, 'surface_unitaire' => $cap->surface_unitaire !== null ? (float) $cap->surface_unitaire : null,
                    'surface_totale' => $cap->surface_totale !== null ? (float) $cap->surface_totale : null, 'source' => $cap->source,
                    'observations' => $cap->observations] as $cle => $valeur) {
                    $c->setCellValue($colC($cle).$ligneC, $valeur);
                }
                $ligneC++;
            }
        }

        // Formules d'aide, identiques au fichier de référence.
        $finS = max($sites->count() + 1, $config['lignes_modele_sites'] + 1);
        $finC = max($ligneC - 1, $config['lignes_modele_capacites'] + 1);
        $k = __('admin.sites.import.controle');
        [$A, $B, $Cv] = [$colS('code'), $colS('nom'), $colS('ville')];
        for ($r = 2; $r <= $finS; $r++) {
            $s->setCellValue($colS('controle').$r, "=IF({$A}{$r}=\"\",\"\",IF(COUNTIF(\${$A}\$2:\${$A}\${$finS},{$A}{$r})>1,\"{$k['doublon']}\",IF(LEN({$A}{$r})<>3,\"{$k['code']}\","
                ."IF(OR({$B}{$r}=\"\",{$Cv}{$r}=\"\"),\"{$k['incomplet']}\",\"{$k['ok']}\"))))");
        }
        [$cA, $cB, $cC, $cD, $cE, $cF, $cG, $cH] = array_map($colC, ['site', 'niveau', 'zone', 'type', 'nombre_prevu', 'surface_unitaire', 'surface_totale', 'surface_retenue']);
        $onglet = "'".self::ONGLET_SITES."'";
        for ($r = 2; $r <= $finC; $r++) {
            $c->setCellValue($cH.$r, "=IF({$cG}{$r}<>\"\",{$cG}{$r},IF(AND({$cE}{$r}<>\"\",{$cF}{$r}<>\"\"),{$cE}{$r}*{$cF}{$r},\"\"))");
            $c->setCellValue($colC('controle').$r, "=IF({$cA}{$r}=\"\",\"\",IF(COUNTIF({$onglet}!\${$A}\$2:\${$A}\${$finS},{$cA}{$r})=0,\"{$k['site_inconnu']}\","
                ."IF({$cD}{$r}=\"\",\"{$k['incomplet']}\",IF(COUNTIFS(\${$cA}\$2:\${$cA}\${$finC},{$cA}{$r},\${$cB}\$2:\${$cB}\${$finC},{$cB}{$r}&\"\",\${$cC}\$2:\${$cC}\${$finC},{$cC}{$r}&\"\",\${$cD}\$2:\${$cD}\${$finC},{$cD}{$r})>1,\"{$k['doublon']}\","
                ."IF(AND({$cE}{$r}=\"\",{$cH}{$r}=\"\"),\"{$k['a_completer']}\",\"{$k['ok']}\")))))");
        }

        // Formats et listes déroulantes.
        $s->getStyle("{$colS('livraison_prevue')}2:{$colS('livraison_prevue')}{$finS}")->getNumberFormat()->setFormatCode('mm/yyyy');
        $this->liste($s, $colS('phase'), $finS, '"'.implode(',', array_map(fn (PhaseSite $p) => $p->label(), PhaseSite::cases())).'"');
        $this->liste($c, $cA, $finC, "{$onglet}!\${$A}\$2:\${$A}\${$finS}");
        $this->liste($c, $cB, $finC, '"'.Niveau::listeUtilisable()->pluck('code')->implode(',').'"');
        $this->liste($c, $cC, $finC, '"'.ZoneSite::query()->utilisables()->orderBy('id')->pluck('code')->implode(',').'"');
        $this->liste($c, $cD, $finC, '"'.TypeBoutique::query()->utilisables()->whereNotNull('code')->orderBy('ordre')->pluck('code')->implode(',').'"');
        $classeur->setActiveSheetIndex(0);

        return $classeur;
    }

    private function entetes(Worksheet $feuille, array $colonnes, array $libelles): void
    {
        foreach ($colonnes as $i => $cle) {
            $feuille->setCellValue([$i + 1, 1], $libelles[$cle]);
            $feuille->getColumnDimensionByColumn($i + 1)->setWidth(in_array($cle, ['nom', 'observations', 'source_donnees'], true) ? 40 : 18);
            if (in_array($cle, self::CALCULEES, true)) {
                $feuille->getStyle([$i + 1, 1])->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB(self::FOND_CALCULE);
            }
        }
        $feuille->getStyle([1, 1, count($colonnes), 1])->getFont()->setBold(true);
        $feuille->freezePane('A2');
    }

    private function liste(Worksheet $feuille, string $colonne, int $fin, string $formule): void
    {
        $validation = $feuille->getDataValidation($colonne.'2');
        $validation->setType(DataValidation::TYPE_LIST)->setAllowBlank(true)->setShowDropDown(true)->setFormula1($formule);
        $validation->setSqref("{$colonne}2:{$colonne}{$fin}");
    }

    // ------------------------------------------------------------------ Import

    /** Lecture et contrôle des onglets « Sites » et « Capacités », sans aucune écriture en base. */
    public function analyser(string $chemin): AnalyseImportSites
    {
        $analyse = new AnalyseImportSites;
        $e = __('admin.sites.import.erreurs');

        try {
            $lecteur = new LecteurXlsx;
            $lecteur->setReadDataOnly(true);
            $lecteur->setLoadSheetsOnly([self::ONGLET_SITES, self::ONGLET_CAPACITES]);
            $classeur = $lecteur->load($chemin);
        } catch (Throwable) {
            return $analyse->erreur(null, null, null, $e['illisible']);
        }
        $feuilleSites = $classeur->getSheetByName(self::ONGLET_SITES);
        $feuilleCapacites = $classeur->getSheetByName(self::ONGLET_CAPACITES);
        foreach ([self::ONGLET_SITES => $feuilleSites, self::ONGLET_CAPACITES => $feuilleCapacites] as $nom => $feuille) {
            if ($feuille === null) {
                $analyse->erreur($nom, null, null, $e['onglet'], ['onglet' => $nom]);
            }
        }
        if (! $analyse->estValide()) {
            return $analyse;
        }

        $lignesSites = $this->lignes($analyse, $feuilleSites, self::ONGLET_SITES, self::COLONNES_SITES, __('admin.sites.import.colonnes_sites'), ['code', 'nom', 'ville']);
        $lignesCapacites = $this->lignes($analyse, $feuilleCapacites, self::ONGLET_CAPACITES, self::COLONNES_CAPACITES, __('admin.sites.import.colonnes_capacites'), ['site', 'type']);
        $classeur->disconnectWorksheets();
        if (! $analyse->estValide()) {
            return $analyse;
        }

        $this->controlerSites($analyse, $lignesSites);
        $this->controlerCapacites($analyse, $lignesCapacites);

        if ($analyse->sites() === [] && $analyse->capacites() === [] && $analyse->estValide()) {
            $analyse->erreur(null, null, null, $e['vide']);
        }

        return $analyse;
    }

    /**
     * Lignes non vides d'un onglet, indexées par numéro de ligne ; colonnes repérées par leur libellé en ligne 1.
     *
     * @return array<int, array<string, mixed>>
     */
    private function lignes(AnalyseImportSites $analyse, Worksheet $feuille, string $onglet, array $colonnes, array $libelles, array $obligatoires): array
    {
        $normaliser = fn ($v) => mb_strtolower(str_replace(['’', "\u{00A0}"], ["'", ' '], trim((string) $v)));
        $index = array_flip(array_map($normaliser, $libelles));
        $positions = [];
        $max = Coordinate::columnIndexFromString($feuille->getHighestDataColumn());
        for ($i = 1; $i <= $max; $i++) {
            $cle = $index[$normaliser($feuille->getCell([$i, 1])->getValue())] ?? null;
            if ($cle !== null && ! in_array($cle, self::CALCULEES, true)) {
                $positions[$cle] = $i;
            }
        }
        foreach ($obligatoires as $cle) {
            if (! isset($positions[$cle])) {
                $analyse->erreur($onglet, 1, $libelles[$cle], __('admin.sites.import.erreurs.colonne_absente'));
            }
        }
        $derniere = $feuille->getHighestDataRow();
        if ($derniere - 1 > (int) config('raaga.referentiel_sites.lignes_max')) {
            $analyse->erreur($onglet, null, null, __('admin.sites.import.erreurs.trop_de_lignes'), ['max' => config('raaga.referentiel_sites.lignes_max')]);
        }
        if (! $analyse->estValide()) {
            return [];
        }

        $lignes = [];
        for ($r = 2; $r <= $derniere; $r++) {
            $v = [];
            foreach ($positions as $cle => $i) {
                $brut = $feuille->getCell([$i, $r])->getValue();
                $v[$cle] = is_string($brut) ? (trim($brut) === '' ? null : trim($brut)) : $brut;
            }
            if (collect($v)->contains(fn ($x) => $x !== null)) {
                $lignes[$r] = $v; // Les lignes vides sont ignorées.
            }
        }

        return $lignes;
    }

    /** @param array<int, array<string, mixed>> $lignes */
    private function controlerSites(AnalyseImportSites $analyse, array $lignes): void
    {
        $libelles = __('admin.sites.import.colonnes_sites');
        $e = __('admin.sites.import.erreurs');
        $existants = Site::query()->whereIn('code', collect($lignes)->pluck('code')->filter()->map(fn ($c) => mb_strtoupper((string) $c)))->get()->keyBy('code');
        $vus = [];

        foreach ($lignes as $r => $v) {
            $d = $v;
            $d['code'] = $v['code'] === null ? null : mb_strtoupper((string) $v['code']);
            $d['phase'] = $v['phase'] === null ? null : (PhaseSite::depuis((string) $v['phase'])?->value ?? (string) $v['phase']);
            $d['livraison_prevue'] = $this->mois($v['livraison_prevue'] ?? null);
            foreach (['latitude', 'longitude'] as $cle) {
                $d[$cle] = is_string($v[$cle] ?? null) ? str_replace(',', '.', $v[$cle]) : ($v[$cle] ?? null);
            }
            foreach (['nom', 'ville', 'arrondissement', 'adresse', 'maitre_ouvrage', 'maitre_oeuvre', 'reference_plans', 'version_plans', 'source_donnees'] as $cle) {
                $d[$cle] = isset($v[$cle]) ? (string) $v[$cle] : null;
            }

            $validateur = Validator::make($d, collect(FicheSite::regles(null, false, $d))->except('description')->all(), [], $libelles);
            $avant = $analyse->nombreErreurs();
            foreach ($validateur->errors()->messages() as $champ => $messages) {
                $analyse->erreur(ReferentielSitesExcel::ONGLET_SITES, $r, $libelles[$champ] ?? $champ, $champ === 'phase'
                    ? strtr($e['phase'], [':valeurs' => implode(', ', array_map(fn ($p) => $p->label(), PhaseSite::cases()))]) : $messages[0]);
            }
            if ($d['code'] !== null && isset($vus[$d['code']])) {
                $analyse->erreur(self::ONGLET_SITES, $r, $libelles['code'], $e['doublon_site'], ['code' => $d['code'], 'ligne' => $vus[$d['code']]]);
            }
            $existant = $existants[$d['code']] ?? null;
            if ($existant && ! $existant->estModifiable()) {
                $analyse->erreur(self::ONGLET_SITES, $r, $libelles['code'], $e['site_verrouille'], ['code' => $d['code'], 'statut' => mb_strtolower($existant->statut->label())]);
            }
            if ($d['code'] !== null) {
                $vus[$d['code']] ??= $r;
                $analyse->noterCode($d['code']);
            }
            if ($analyse->nombreErreurs() === $avant) {
                $analyse->ajouterSite($d['code'], $r, $existant?->id, FicheSite::attributs(collect($d)->only(Site::PARAMETRES)->except('description')->all()));
            }
        }
    }

    /** @param array<int, array<string, mixed>> $lignes */
    private function controlerCapacites(AnalyseImportSites $analyse, array $lignes): void
    {
        $libelles = __('admin.sites.import.colonnes_capacites');
        $e = __('admin.sites.import.erreurs');
        $onglet = self::ONGLET_CAPACITES;
        $types = TypeBoutique::query()->get();
        $niveaux = Niveau::query()->get()->keyBy('code');
        $zones = ZoneSite::query()->get()->keyBy('code');
        $zonesUtilisables = $zones->filter->estUtilisable()->keys()->all();
        $codesFichier = array_keys($analyse->sites());
        $enBase = Site::query()->whereIn('code', collect($lignes)->pluck('site')->filter()->map(fn ($c) => mb_strtoupper((string) $c)))->get()->keyBy('code');
        $vus = [];

        foreach ($lignes as $r => $v) {
            $avant = $analyse->nombreErreurs();
            $err = fn (string $cle, string $motif, array $p = []) => $analyse->erreur($onglet, $r, $libelles[$cle], $motif, $p);

            $code = $v['site'] === null ? null : mb_strtoupper((string) $v['site']);
            if ($code === null) {
                $err('site', $e['obligatoire']);
            } elseif ($analyse->siteRefuse($code)) {
                // Site présent dans l'onglet Sites mais refusé : ses erreurs y sont déjà ; la ligne est contrôlée quand même.
            } elseif (! in_array($code, $codesFichier, true) && ! isset($enBase[$code])) {
                $err('site', $e['site_inconnu'], ['code' => $code]);
            } elseif (! in_array($code, $codesFichier, true) && ! $enBase[$code]->estModifiable()) {
                $err('site', $e['site_verrouille'], ['code' => $code, 'statut' => mb_strtolower($enBase[$code]->statut->label())]);
            }

            $niveau = $v['niveau'] === null ? null : $niveaux->get(mb_strtoupper((string) $v['niveau']));
            if ($v['niveau'] !== null && $niveau === null) {
                $err('niveau', __('admin.zones.regles.niveau_inconnu', ['code' => mb_strtoupper((string) $v['niveau'])]));
            } elseif ($niveau !== null && ! $niveau->estUtilisable()) {
                $err('niveau', $niveau->motifInutilisable());
            }
            $zone = $v['zone'] === null ? null : mb_strtoupper((string) $v['zone']);
            if ($zone !== null && ! isset($zones[$zone])) {
                $err('zone', $e['valeur'], ['valeurs' => implode(', ', $zonesUtilisables)]);
            } elseif ($zone !== null && ! $zones[$zone]->estUtilisable()) {
                $err('zone', $zones[$zone]->motifInutilisable()); // « zone désactivée », ou pas encore validée.
            }
            $type = $v['type'] === null ? null : $types->first(fn (TypeBoutique $t) => ($t->code !== null && mb_strtoupper((string) $v['type']) === $t->code)
                || mb_strtolower((string) $v['type']) === mb_strtolower($t->libelle));
            if ($v['type'] === null) {
                $err('type', $e['obligatoire']);
            } elseif ($type === null) {
                $err('type', $e['type'], ['valeur' => $v['type']]);
            } elseif (! $type->estUtilisable()) {
                $err('type', $type->motifInutilisable()); // Type désactivé, ou pas encore validé.
            }
            $nombre = $this->entier($v['nombre_prevu'] ?? null);
            if (($v['nombre_prevu'] ?? null) !== null && $nombre === null) {
                $err('nombre_prevu', $e['entier']);
            }
            $surfaces = [];
            foreach (['surface_unitaire', 'surface_totale'] as $cle) {
                $surfaces[$cle] = is_numeric($x = str_replace(',', '.', (string) ($v[$cle] ?? ''))) ? (float) $x : null;
                if (($v[$cle] ?? null) !== null && ($surfaces[$cle] === null || $surfaces[$cle] <= 0)) {
                    $err($cle, $e['surface']);
                }
            }
            foreach (['source' => 200, 'observations' => 500] as $cle => $max) {
                if (mb_strlen((string) ($v[$cle] ?? '')) > $max) {
                    $err($cle, $e['longueur'], ['max' => $max]);
                }
            }
            if ($analyse->nombreErreurs() > $avant) {
                continue;
            }

            $cle = implode('|', [$code, $niveau?->code, $zone, $type->id]);
            if (isset($vus[$cle])) {
                $err('type', $e['doublon_capacite'], ['ligne' => $vus[$cle]]);

                continue;
            }
            $vus[$cle] = $r;
            $analyse->ajouterCapacite($code, $r, [
                'niveau' => $niveau?->code, 'zone' => $zone, 'type_boutique_id' => $type->id, 'nombre_prevu' => $nombre,
                'surface_unitaire' => $surfaces['surface_unitaire'], 'surface_totale' => $surfaces['surface_totale'],
                'source' => isset($v['source']) ? (string) $v['source'] : null, 'observations' => isset($v['observations']) ? (string) $v['observations'] : null,
            ]);
        }
    }

    /**
     * Enregistrement tout ou rien : sites créés en brouillon ou mis à jour (brouillon ou révision seulement), capacités
     * des sites concernés remplacées par celles du fichier. Journalisé (auteur, fichier, empreinte, nombres).
     *
     * @return array{sites_crees: int, sites_mis_a_jour: int, capacites: int}
     *
     * @throws RegleGestionException
     */
    public function importer(AnalyseImportSites $analyse, User $auteur, string $nomFichier, string $empreinte, ?string $chemin = null, array $journal = []): array
    {
        if (! $analyse->estValide()) {
            throw new RegleGestionException(__('admin.sites.import.erreurs.non_valide'));
        }

        return DB::transaction(function () use ($analyse, $auteur, $nomFichier, $empreinte, $chemin, $journal) {
            $compte = ['sites_crees' => 0, 'sites_mis_a_jour' => 0, 'sites_inchanges' => 0, 'capacites' => 0];
            $sites = [];
            foreach ($analyse->sites() as $code => $s) {
                if ($s['site_id'] === null) {
                    $sites[$code] = Site::create([...$s['donnees'], 'statut' => StatutSite::Brouillon, 'cree_par' => $auteur->id]);
                    $compte['sites_crees']++;

                    continue;
                }
                $site = Site::query()->lockForUpdate()->findOrFail($s['site_id']);
                $this->exigerModifiable($site);
                $site->fill(collect($s['donnees'])->except('code')->all()); // Le code d'un site existant ne change jamais.
                $sites[$code] = $site;
                if (! $site->isDirty()) {
                    $compte['sites_inchanges']++;

                    continue;
                }
                $site->save();
                $compte['sites_mis_a_jour']++;
            }

            foreach ($analyse->capacites() as $code => $capacites) {
                $site = $sites[$code] ?? Site::query()->where('code', $code)->lockForUpdate()->firstOrFail();
                $this->exigerModifiable($site);
                $site->capacites()->delete();
                foreach ($capacites as $c) {
                    $site->capacites()->create($c['donnees']);
                    $compte['capacites']++;
                }
            }

            $this->journal->ecrire($auteur, 'sites.importes', 'Site', null, null, ['fichier' => $nomFichier, 'empreinte' => $empreinte, 'chemin' => $chemin, ...$compte, ...$journal]);

            return $compte;
        });
    }

    /** @throws RegleGestionException */
    private function exigerModifiable(Site $site): void
    {
        if (! $site->estModifiable()) {
            throw new RegleGestionException(__('admin.sites.import.erreurs.site_verrouille', ['code' => $site->code, 'statut' => mb_strtolower($site->statut->label())]));
        }
    }

    /** Montant entier en FCFA (« 16245000000 », « 16 245 000 000 ») ; la valeur brute si elle n'en est pas un (refusée ensuite). */
    private function montant(mixed $valeur): mixed
    {
        if ($valeur === null || is_int($valeur)) {
            return $valeur;
        }
        if (is_float($valeur)) {
            return floor($valeur) === $valeur ? (int) $valeur : $valeur;
        }
        $nettoye = str_replace([' ', "\u{00A0}", "\u{202F}"], '', (string) $valeur);

        return preg_match('/^\d+$/', $nettoye) ? (int) $nettoye : $valeur;
    }

    /** Mois de livraison au format AAAA-MM (date Excel, « 07/2026 » ou « 2026-07 ») ; la valeur brute sinon (refusée ensuite). */
    private function mois(mixed $valeur): mixed
    {
        if ($valeur === null) {
            return null;
        }
        if (is_numeric($valeur)) {
            return DateExcel::excelToDateTimeObject((float) $valeur)->format('Y-m');
        }
        if (preg_match('/^(\d{1,2})\/(\d{4})$/', (string) $valeur, $m)) {
            return sprintf('%04d-%02d', $m[2], $m[1]);
        }
        if (preg_match('/^(\d{4})-(\d{2})(-\d{2})?$/', (string) $valeur, $m)) {
            return $m[1].'-'.$m[2];
        }

        return (string) $valeur;
    }

    private function entier(mixed $valeur): ?int
    {
        if (is_int($valeur)) {
            return $valeur >= 0 ? $valeur : null;
        }
        if (is_float($valeur)) {
            return floor($valeur) === $valeur && $valeur >= 0 ? (int) $valeur : null;
        }

        return is_string($valeur) && preg_match('/^\d+$/', $valeur) ? (int) $valeur : null;
    }
}
