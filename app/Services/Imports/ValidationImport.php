<?php

namespace App\Services\Imports;

use App\Http\Controllers\Admin\SitesController;
use App\Models\ImportValidation;
use App\Models\ImportValidationSite;
use App\Models\Site;
use App\Services\Audit\Journal;
use App\Services\Backoffice\RegleGestionException;
use App\Services\Boutiques\AnalyseImport;
use App\Services\Sites\AnalyseImportSites;
use App\Services\Validation\NotificationDecision;
use App\Services\Validation\SoumissionGroupee;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Statut de validation choisi à l'import (« Brouillon » ou « Soumettre au VALIDATEUR ») et décision sur un import
 * entier. Aucune règle nouvelle : chaque site passe par les actions « Soumettre », « Confirmer » et « Rejeter » de sa
 * fiche (mêmes contrôles, versions, journal) ; la décision sur l'import envoie une seule notification, à son auteur.
 */
class ValidationImport
{
    public function __construct(private readonly SoumissionGroupee $soumission, private readonly Journal $journal, private readonly NotificationDecision $notifications) {}

    /** Statut choisi dans la fenêtre « Confirmer l'import » (sans choix : brouillon, comportement d'avant). */
    public function statutChoisi(Request $request): string
    {
        return $request->validate(['statut_apres' => ['nullable', Rule::in([ImportValidation::BROUILLON, ImportValidation::SOUMETTRE])]])['statut_apres']
            ?? ImportValidation::BROUILLON;
    }

    /**
     * Import confirmé : l'import est enregistré avec son statut (le journal de l'import le reprend), puis, s'il est
     * à soumettre, chaque site concerné est soumis ; un site qui échoue au contrôle reste en brouillon.
     *
     * @param  Closure(array): array  $importer  importe le fichier ; reçoit les valeurs à ajouter au journal, rend le compte
     * @return array{import: ImportValidation, compte: array, message: ?string}
     */
    public function importer(Request $request, string $entite, AnalyseImport|AnalyseImportSites $analyse, array $depot, Closure $importer): array
    {
        $statut = $this->statutChoisi($request);
        [$import, $compte] = DB::transaction(function () use ($request, $entite, $analyse, $depot, $importer, $statut) {
            $import = ImportValidation::create([
                'entite' => $entite, 'fichier' => $depot['nom'], 'chemin' => $depot['chemin'] ?? null, 'empreinte' => $depot['empreinte'] ?? null,
                'auteur_id' => $request->user()->id, 'statut_apres' => $statut, 'contenu' => [], 'resume' => $this->resume($analyse),
                'avertissements' => $analyse instanceof AnalyseImport ? $analyse->avertissements() : [],
            ]);
            $compte = $importer(['import_id' => $import->id, 'statut_apres' => $statut]);
            $import->update(['contenu' => $compte]);
            foreach ($this->sitesConcernes($analyse) as $site) {
                ImportValidationSite::create(['import_id' => $import->id, 'site_id' => $site->id]);
            }

            return [$import, $compte];
        });

        return ['import' => $import, 'compte' => $compte, 'message' => $statut === ImportValidation::SOUMETTRE ? $this->soumettreSites($request, $import) : null];
    }

    /**
     * Décision sur un import entier : tous les sites encore soumis par l'import sont confirmés (ou rejetés, motif
     * commun) par l'action de leur fiche ; un site déjà traité séparément est compté « déjà traité ».
     *
     * @param  string  $decision  confirmer | rejeter
     *
     * @throws RegleGestionException
     */
    public function decider(Request $request, ImportValidation $import, string $decision, ?string $motif): string
    {
        $user = $request->user();
        $import->load('sites.site');
        if ($import->auteur_id === $user->id) {
            throw new RegleGestionException(__('admin.a_valider.erreurs.auto_validation'));
        }
        if ($import->decision !== null || $import->statutValidation() !== 'SOUMIS') {
            throw new RegleGestionException(__('admin.a_valider.erreurs.deja_traite'));
        }

        $faits = 0;
        $deja = 0;
        $refus = [];
        NotificationDecision::sansNotification(function () use ($request, $import, $decision, $motif, &$faits, &$deja, &$refus) {
            foreach ($import->sites->filter(fn (ImportValidationSite $l) => $l->soumis_le !== null) as $ligne) {
                if (! $ligne->enAttente()) {
                    $ligne->resultat ??= 'DEJA_TRAITE';
                    $ligne->save();
                    $deja++;

                    continue;
                }
                $erreur = $this->decisionSite($request, $ligne->site, $decision, $motif);
                if ($erreur !== null) {
                    $refus[] = $ligne->site->code.' ('.$erreur.')';

                    continue;
                }
                $ligne->update(['resultat' => $decision === 'confirmer' ? 'CONFIRME' : 'REJETE']);
                $faits++;
            }
        });

        $import->forceFill(['decision' => $decision === 'confirmer' ? ImportValidation::VALIDE : ImportValidation::REJETE,
            'motif_rejet' => $motif, 'decide_par' => $user->id, 'decide_le' => now()])->save();
        $this->journal->ecrire($user, $decision === 'confirmer' ? 'import.valide' : 'import.rejete', 'ImportValidation', $import->id, null,
            ['confirmes' => $decision === 'confirmer' ? $faits : 0, 'rejetes' => $decision === 'rejeter' ? $faits : 0, 'deja_traites' => $deja, 'motif' => $motif]);
        // Une seule notification par décision, à l'auteur de l'import.
        $this->notifications->envoyer($import->auteur_id, __('admin.a_valider.elements.import'), $import->fichier, $decision, $motif,
            route('admin.imports-validation.show', $import));

        $parties = [trans_choice('admin.imports_validation.resultat.'.$decision, $faits, ['n' => $faits])];
        if ($deja > 0) {
            $parties[] = trans_choice('admin.imports_validation.resultat.deja', $deja, ['n' => $deja]);
        }
        if ($refus !== []) {
            $parties[] = trans_choice('admin.imports_validation.resultat.refus', count($refus), ['n' => count($refus)]).' : '.implode(' ; ', $refus);
        }

        return implode(', ', $parties).'.';
    }

    /** « 1 site soumis, 1 resté en brouillon : SAR, ajoutez au moins une boutique… » */
    private function soumettreSites(Request $request, ImportValidation $import): string
    {
        $soumis = 0;
        $brouillons = [];
        foreach ($import->sites()->with('site')->get() as $ligne) {
            $raison = $this->soumission->soumettreUn($request, 'site', $ligne->site);
            if ($raison === null) {
                $ligne->update(['soumis_le' => $ligne->site->fresh()->soumis_le]);
                $soumis++;

                continue;
            }
            $ligne->update(['raison_brouillon' => $raison]);
            $brouillons[] = $ligne->site->code.', '.rtrim(lcfirst($raison), '.');
        }

        $message = trans_choice('admin.imports_validation.soumission.soumis', $soumis, ['n' => $soumis]);
        if ($brouillons !== []) {
            $message .= ', '.trans_choice('admin.imports_validation.soumission.brouillon', count($brouillons), ['n' => count($brouillons)]).' : '.implode(' ; ', $brouillons);
        }

        return $message.'.';
    }

    /** Action « Confirmer » ou « Rejeter » de la fiche du site ; rend la raison d'un refus, ou null. */
    private function decisionSite(Request $request, Site $site, string $decision, ?string $motif): ?string
    {
        $session = $request->session();
        $session->forget(['succes', 'erreur']);
        $sousRequete = Request::create($request->url(), 'POST', ['motif' => $motif]);
        $sousRequete->setUserResolver(fn () => $request->user());
        $sousRequete->setLaravelSession($session);
        try {
            app(SitesController::class)->{$decision}($sousRequete, $site);
        } catch (ValidationException $e) {
            return collect($e->errors())->flatten()->first();
        } catch (Throwable $e) {
            report($e);

            return __('admin.soumission.erreurs.element');
        }
        $erreur = $session->get('erreur');
        $session->forget(['succes', 'erreur']);

        return $erreur;
    }

    /** @return Collection<int, Site> */
    private function sitesConcernes(AnalyseImport|AnalyseImportSites $analyse)
    {
        return $analyse instanceof AnalyseImport
            ? Site::query()->whereIn('id', array_unique(array_column($analyse->lignes(), 'site_id')))->orderBy('code')->get()
            : Site::query()->whereIn('code', array_unique([...array_keys($analyse->sites()), ...array_keys($analyse->capacites())]))->orderBy('code')->get();
    }

    /**
     * Résumé par site, niveau et type : boutiques (lignes, créées, mises à jour) ou capacités prévues.
     *
     * @return list<array{site_id: ?int, site: ?string, niveau: ?string, type_boutique_id: int, lignes: int, creees: int, mises_a_jour: int, nombre_prevu: int}>
     */
    private function resume(AnalyseImport|AnalyseImportSites $analyse): array
    {
        $lignes = [];
        if ($analyse instanceof AnalyseImport) {
            foreach ($analyse->lignes() as $l) {
                $cle = $l['site_id'].'|'.$l['donnees']['niveau'].'|'.$l['donnees']['type_boutique_id'];
                $lignes[$cle] ??= ['site_id' => $l['site_id'], 'site' => null, 'niveau' => $l['donnees']['niveau'], 'type_boutique_id' => (int) $l['donnees']['type_boutique_id'],
                    'lignes' => 0, 'creees' => 0, 'mises_a_jour' => 0, 'nombre_prevu' => 0];
                $lignes[$cle]['lignes']++;
                $lignes[$cle][$l['boutique_id'] === null ? 'creees' : 'mises_a_jour']++;
            }
        } else {
            foreach ($analyse->capacites() as $code => $capacites) {
                foreach ($capacites as $c) {
                    $d = $c['donnees'];
                    $cle = $code.'|'.$d['niveau'].'|'.$d['type_boutique_id'];
                    $lignes[$cle] ??= ['site_id' => null, 'site' => (string) $code, 'niveau' => $d['niveau'], 'type_boutique_id' => (int) $d['type_boutique_id'],
                        'lignes' => 0, 'creees' => 0, 'mises_a_jour' => 0, 'nombre_prevu' => 0];
                    $lignes[$cle]['lignes']++;
                    $lignes[$cle]['nombre_prevu'] += (int) ($d['nombre_prevu'] ?? 0);
                }
            }
        }

        return array_values($lignes);
    }
}
