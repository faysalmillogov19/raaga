<?php

namespace App\Services\Boutiques;

use App\Enums\StatutBoutique;
use App\Enums\StatutSite;
use App\Models\Boutique;
use App\Models\HistoriqueCode;
use App\Models\Niveau;
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
    /** Colonnes obligatoires dans l'en-tête et dans chaque ligne. */
    private const OBLIGATOIRES = ['site', 'niveau', 'bloc', 'numero', 'type', 'surface'];

    public function __construct(private readonly Journal $journal) {}

    // ------------------------------------------------------------------ Modèle et export

    /** Modèle vierge (lignes préparées avec les formules) ou export des boutiques du site ; sans site : modèle de l'import combiné. */
    public function telecharger(?Site $site, bool $avecBoutiques): StreamedResponse
    {
        $boutiques = $site && $avecBoutiques ? $site->boutiques()->with('type')->get() : collect();
        $classeur = $this->classeur($boutiques);
        $nom = __($avecBoutiques ? 'admin.sites.excel.nom_export' : 'admin.sites.excel.nom_modele', [
            'site' => $site?->code ?? ($site?->id ?? __('admin.sites.excel.tous_sites')), 'date' => now()->format('Ymd'),
        ]);

        return response()->streamDownload(function () use ($classeur) {
            (new EcrivainXlsx($classeur))->save('php://output');
            $classeur->disconnectWorksheets();
        }, $nom, ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']);
    }

    /** Export de boutiques de plusieurs sites (liste filtrée du référencement), au même format. @param Collection<int, Boutique> $boutiques */
    public function telechargerBoutiques(Collection $boutiques): StreamedResponse
    {
        $classeur = $this->classeur($boutiques);

        return response()->streamDownload(function () use ($classeur) {
            (new EcrivainXlsx($classeur))->save('php://output');
            $classeur->disconnectWorksheets();
        }, __('admin.sites.excel.nom_export', ['site' => __('admin.sites.excel.tous_sites'), 'date' => now()->format('Ymd')]),
            ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']);
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
                'site' => $b->site?->code, 'niveau' => $b->niveau, 'bloc' => $b->bloc, 'numero' => $b->code ? (int) $b->numero : $b->numero,
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
        $this->liste($feuille, $n."2:{$n}{$derniere}", Niveau::listeUtilisable()->pluck('code')->all());
        $codesTypes = TypeBoutique::query()->utilisables()->whereNotNull('code')->orderBy('ordre')->pluck('code')->all();
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

    /** Code site normalisé : majuscules, espaces retirés. */
    public static function normaliserCodeSite(mixed $valeur): string
    {
        return mb_strtoupper(preg_replace('/\s+/u', '', (string) $valeur));
    }

    /**
     * Lecture et contrôle du fichier, sans aucune écriture en base. Avec un site ($site), l'import se fait depuis
     * sa fiche : la colonne Site vide prend son code, tout autre site est refusé. Sans site (import combiné), chaque
     * ligne désigne un site existant ; un import de boutiques ne crée jamais de site.
     */
    public function analyser(?Site $site, string $chemin): AnalyseImport
    {
        $analyse = new AnalyseImport;
        $e = __('admin.sites.excel.erreurs');
        $a = __('admin.sites.excel.avertissements');
        $libelles = __('admin.sites.excel.colonnes');

        if ($site !== null && $site->code === null) {
            return $analyse->erreur(null, null, __('admin.sites.regles.sans_code_site'));
        }
        if ($site !== null && ! $site->estModifiable()) {
            return $analyse->erreur(null, null, $this->motifVerrouille($site));
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

        // En-tête : les colonnes sont repérées par leur libellé, quel que soit leur ordre (Contrôle ignoré).
        $positions = [];
        $maxColonne = Coordinate::columnIndexFromString($feuille->getHighestDataColumn());
        for ($i = 1; $i <= $maxColonne; $i++) {
            $cle = array_search(mb_strtolower(trim((string) $feuille->getCell([$i, 1])->getValue())), array_map('mb_strtolower', $libelles), true);
            if ($cle !== false && $cle !== 'controle') {
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
        $niveaux = Niveau::query()->get()->keyBy('code');
        $sites = Site::query()->whereNotNull('code')->get()->keyBy('code');
        $sitesRefuses = [];
        $siteVide = 0;
        $vus = [];
        for ($ligne = 2; $ligne <= $derniere; $ligne++) {
            $v = [];
            foreach ($positions as $cle => $i) {
                $cellule = $feuille->getCell([$i, $ligne]);
                // Colonne Code : formule d'aide ; seule sa dernière valeur calculée par Excel compte.
                $brut = $cle === 'code' && $cellule->isFormula() ? $cellule->getOldCalculatedValue() : $cellule->getValue();
                $v[$cle] = is_string($brut) ? trim($brut) : $brut;
            }
            if (collect($v)->except('code')->every(fn ($x) => $x === null || $x === '')) {
                continue; // Ligne vide.
            }

            $erreurs = $analyse->nombreErreurs();
            $err = fn (string $cle, string $motif, array $p = []) => $analyse->erreur($ligne, $libelles[$cle], $motif, $p);

            foreach (array_diff(self::OBLIGATOIRES, ['site']) as $cle) {
                if ($v[$cle] === null || $v[$cle] === '') {
                    $err($cle, $e['obligatoire']);
                }
            }

            // Site de la ligne (règles de correspondance du code site).
            $codeSite = self::normaliserCodeSite($v['site']);
            $siteLigne = null;
            if ($codeSite === '') {
                if ($site !== null) {
                    $siteLigne = $site;
                    $siteVide++;
                } else {
                    $err('site', $e['obligatoire']);
                }
            } elseif ($site !== null && $codeSite === $site->code) {
                $siteLigne = $site;
            } elseif (($renomme = HistoriqueCode::siteAnciennementCode($codeSite)) && $renomme->code !== $codeSite) {
                $err('site', $e['site_renomme'], ['ligne' => $ligne, 'code' => $codeSite, 'nouveau' => $renomme->code]);
            } elseif ($site !== null && isset($sites[$codeSite])) {
                $err('site', $e['autre_site'], ['ligne' => $ligne, 'code' => $codeSite, 'site' => $site->code]);
            } elseif (isset($sites[$codeSite])) {
                $siteLigne = $sites[$codeSite];
                if (! $siteLigne->estModifiable()) {
                    $sitesRefuses[$codeSite] = $siteLigne; // Import entier refusé (voir plus bas).
                    $siteLigne = null;
                }
            } else {
                $suggestion = $this->codeProche($codeSite, $sites->keys()->all());
                $err('site', $suggestion ? $e['site_inconnu_suggestion'] : $e['site_inconnu'], ['ligne' => $ligne, 'code' => $codeSite, 'suggestion' => $suggestion]);
            }

            $niveau = $v['niveau'] === null || $v['niveau'] === '' ? null : $niveaux->get(mb_strtoupper((string) $v['niveau']));
            if ($v['niveau'] !== null && $v['niveau'] !== '' && $niveau === null) {
                $err('niveau', __('admin.zones.regles.niveau_inconnu', ['code' => mb_strtoupper((string) $v['niveau'])]));
            } elseif ($niveau !== null && ! $niveau->estUtilisable()) {
                $err('niveau', $niveau->motifInutilisable()); // Niveau désactivé, ou pas encore validé.
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
            } elseif ($type !== null && ! $type->estUtilisable()) {
                $err('type', $type->motifInutilisable()); // Type désactivé, ou pas encore validé.
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

            if ($analyse->nombreErreurs() > $erreurs || $siteLigne === null) {
                continue;
            }

            $code = Boutique::composerCode($siteLigne->code, $niveau->code, $bloc, (string) $numero);
            $codeFichier = isset($v['code']) && $v['code'] !== '' ? mb_strtoupper(preg_replace('/\s+/u', '', (string) $v['code'])) : null;
            if ($codeFichier !== null && $codeFichier !== $code) {
                $analyse->avertir($a['code_recalcule'], ['ligne' => $ligne, 'lu' => $codeFichier, 'code' => $code]);
            }
            if (isset($vus[$code])) {
                $err('numero', $e['doublon_fichier'], ['code' => $code, 'ligne' => $vus[$code]]);

                continue;
            }
            $vus[$code] = $ligne;

            $existante = Boutique::withTrashed()->where('code', $code)->first();
            if ($existante?->trashed() || HistoriqueCode::query()->boutiques()->where('ancien_code', $code)->exists()) {
                $err('numero', $e['supprimee'], ['code' => $code]);

                continue;
            }
            if ($existante && ($existante->site_id !== $siteLigne->id || $existante->statut->verrouillee())) {
                $err('numero', $e['engagee'], ['code' => $code, 'statut' => mb_strtolower($existante->statut->label())]);

                continue;
            }

            $analyse->ajouter($ligne, $code, $siteLigne->id, $existante?->id, [
                'niveau' => $niveau->code, 'bloc' => $bloc, 'numero' => str_pad((string) $numero, Boutique::CHIFFRES_NUMERO, '0', STR_PAD_LEFT),
                'type_boutique_id' => $type->id, 'surface' => $surface,
                'planche' => ($v['planche'] ?? '') === '' ? null : (string) $v['planche'],
                'observations' => ($v['observations'] ?? '') === '' ? null : (string) $v['observations'],
            ], $niveau->libelle, $type->libelle);
        }
        $classeur->disconnectWorksheets();

        // Un site soumis ou confirmé dans le fichier : l'import entier est refusé.
        foreach ($sitesRefuses as $refuse) {
            $analyse->erreur(null, null, $this->motifVerrouille($refuse));
        }
        if ($siteVide > 0) {
            $analyse->avertir(trans_choice('admin.sites.excel.avertissements.site_vide', $siteVide), ['n' => $siteVide, 'code' => $site->code]);
        }
        if ($analyse->lignes() === [] && $analyse->estValide()) {
            $analyse->erreur(null, null, $e['vide']);
        }

        return $analyse;
    }

    /** Code existant le plus proche (distance de Levenshtein ≤ 2), ou null. @param list<string> $codes */
    private function codeProche(string $code, array $codes): ?string
    {
        $proche = null;
        $meilleure = 3;
        foreach ($codes as $candidat) {
            $distance = levenshtein($code, (string) $candidat);
            if ($distance < $meilleure) {
                [$proche, $meilleure] = [(string) $candidat, $distance];
            }
        }

        return $proche;
    }

    public function motifVerrouille(Site $site): string
    {
        return __($site->statut === StatutSite::Confirme ? 'admin.sites.excel.erreurs.site_confirme' : 'admin.sites.excel.erreurs.site_soumis', ['code' => $site->code]);
    }

    /**
     * Enregistrement tout ou rien d'une analyse sans erreur. Une boutique existante (même code) n'est mise à jour
     * que si elle est libre, et chaque site concerné doit rester modifiable : c'est vérifié de nouveau dans la transaction.
     * Aucun site n'est jamais créé.
     *
     * @param  array<string, mixed>  $journal  valeurs ajoutées au journal de l'import (import, statut choisi)
     * @return array{creees: int, mises_a_jour: int, inchangees: int}
     *
     * @throws RegleGestionException
     */
    public function importer(AnalyseImport $analyse, User $auteur, string $nomFichier, string $empreinte, ?string $chemin = null, array $journal = []): array
    {
        if (! $analyse->estValide()) {
            throw new RegleGestionException(__('admin.sites.excel.erreurs.non_valide'));
        }

        return DB::transaction(function () use ($analyse, $auteur, $nomFichier, $empreinte, $chemin, $journal) {
            $sites = Site::query()->whereIn('id', array_unique(array_column($analyse->lignes(), 'site_id')))->lockForUpdate()->get()->keyBy('id');
            foreach ($sites as $site) {
                if (! $site->estModifiable()) {
                    throw new RegleGestionException($this->motifVerrouille($site));
                }
            }

            $comptes = [];
            foreach ($analyse->lignes() as $l) {
                $comptes[$l['site_id']] ??= ['creees' => 0, 'mises_a_jour' => 0, 'inchangees' => 0];
                if ($l['boutique_id'] === null) {
                    $sites[$l['site_id']]->boutiques()->create([...$l['donnees'], 'statut' => StatutBoutique::Disponible]);
                    $comptes[$l['site_id']]['creees']++;

                    continue;
                }
                $boutique = Boutique::query()->lockForUpdate()->findOrFail($l['boutique_id']);
                if ($boutique->statut->verrouillee()) {
                    throw new RegleGestionException(__('admin.sites.excel.erreurs.engagee', ['code' => $l['code'], 'statut' => mb_strtolower($boutique->statut->label())]));
                }
                // Le code ne change pas : seuls le type, la surface, la planche et les observations sont mis à jour.
                $boutique->fill(collect($l['donnees'])->except(['niveau', 'bloc', 'numero'])->all());
                if (! $boutique->isDirty()) {
                    $comptes[$l['site_id']]['inchangees']++;

                    continue;
                }
                $boutique->save();
                $comptes[$l['site_id']]['mises_a_jour']++;
            }

            foreach ($comptes as $siteId => $compte) {
                $this->journal->ecrire($auteur, 'boutiques.importees', 'Site', $siteId, null, ['fichier' => $nomFichier, 'empreinte' => $empreinte, 'chemin' => $chemin, ...$compte, ...$journal]);
            }

            return ['creees' => array_sum(array_column($comptes, 'creees')), 'mises_a_jour' => array_sum(array_column($comptes, 'mises_a_jour')),
                'inchangees' => array_sum(array_column($comptes, 'inchangees'))];
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
