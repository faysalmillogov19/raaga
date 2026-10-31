<?php

namespace App\Services\Cartes;

use App\Enums\StatutCarte;
use App\Jobs\GenererFichiersCarte;
use App\Models\Carte;
use App\Models\User;
use App\Services\Audit\Journal;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Fichiers de la carte d'identification (30/10/2026) : PNG recto, PNG verso et PDF, générés à l'avance par Chrome dans un
 * job (GenererFichiersCarte), jamais pendant une requête. Stockés sur le disque privé et rattachés à la version de la
 * carte (cartes/fichiers/{id}/v{version}/) : une nouvelle version (nom, date de naissance, photo, dates) appelle de
 * nouveaux fichiers ; un changement de réglage (signature, cachet, filigranes, armoiries, logo) n'y touche pas, la carte
 * gardant les réglages figés à son émission. Le téléchargement et l'impression au guichet servent ces fichiers.
 * Échec définitif (trois essais) : inscrit sur la carte, plus de nouvelle demande automatique ; le titulaire en est
 * informé et le back-office relance la génération (SUPER_ADMIN, agent de guichet).
 */
class FichiersCarte
{
    public const DISQUE = 'local';

    /** Fichier => nom sur le disque. Le PDF est écrit en dernier : sa présence signale un jeu complet. */
    public const FICHIERS = ['recto' => 'recto.png', 'verso' => 'verso.png', 'pdf' => 'carte.pdf'];

    public function __construct(private readonly RenduCarte $rendu, private readonly Journal $journal) {}

    public static function dossier(Carte $carte): string
    {
        return 'cartes/fichiers/'.$carte->id;
    }

    public function chemin(Carte $carte, string $fichier): string
    {
        return self::dossier($carte).'/v'.$carte->version.'/'.self::FICHIERS[$fichier];
    }

    /** Les trois fichiers de la version courante sont prêts. */
    public function prets(Carte $carte): bool
    {
        $disque = Storage::disk(self::DISQUE);

        return collect(self::FICHIERS)->keys()->every(fn (string $fichier) => $disque->exists($this->chemin($carte, $fichier)));
    }

    public function lire(Carte $carte, string $fichier): string
    {
        return (string) Storage::disk(self::DISQUE)->get($this->chemin($carte, $fichier));
    }

    /**
     * Rendu par Chrome et enregistrement des trois fichiers de la version courante (écrase ceux qui existent) ; les
     * fichiers des versions précédentes sont supprimés. ReglagesCarteManquants si un réglage manque.
     */
    public function generer(Carte $carte): void
    {
        $faces = $this->rendu->pngFaces($carte);
        $pdf = $this->rendu->pdf($carte);

        $disque = Storage::disk(self::DISQUE);
        $disque->put($this->chemin($carte, 'recto'), $faces['recto']);
        $disque->put($this->chemin($carte, 'verso'), $faces['verso']);
        $disque->put($this->chemin($carte, 'pdf'), $pdf);
        foreach ($disque->directories(self::dossier($carte)) as $dossier) {
            if (basename($dossier) !== 'v'.$carte->version) {
                $disque->deleteDirectory($dossier);
            }
        }
        $this->effacerEchec($carte);
    }

    /** Échec définitif d'une génération : inscrit sur la carte (version et cause), pour le titulaire et le back-office. */
    public function marquerEchec(Carte $carte, int $version, Throwable $e): void
    {
        $carte->forceFill(['fichiers_echec_le' => now(), 'fichiers_echec_version' => $version, 'fichiers_echec_cause' => mb_substr($e->getMessage(), 0, 500)])->saveQuietly();
    }

    /** Relance par le back-office : échec effacé, nouvelle génération en tâche de fond, relance tracée au journal d'audit. */
    public function relancer(Carte $carte, User $acteur): void
    {
        $cause = $carte->fichiers_echec_cause;
        $this->effacerEchec($carte);
        $this->demander($carte);
        $this->journal->ecrire($acteur, 'carte.fichiers_relances', 'Carte', $carte->id, null, ['numero' => $carte->numero, 'version' => $carte->version, 'cause' => $cause]);
    }

    /**
     * Cartes courantes et actives dont la génération de la version courante est en échec définitif.
     *
     * @return Collection<int, Carte>
     */
    public static function enEchec(): Collection
    {
        return Carte::query()->courantes()->where('statut', StatutCarte::Active)->whereNotNull('fichiers_echec_le')
            ->whereColumn('fichiers_echec_version', 'version')->with('user')->orderBy('fichiers_echec_le')->get();
    }

    /** Échec effacé, et générations en échec de la carte retirées de la table des tâches en échec. */
    private function effacerEchec(Carte $carte): void
    {
        if ($carte->fichiers_echec_le !== null) {
            $carte->forceFill(['fichiers_echec_le' => null, 'fichiers_echec_version' => null, 'fichiers_echec_cause' => null])->saveQuietly();
        }
        $table = (string) config('queue.failed.table', 'failed_jobs');
        if (! Schema::hasTable($table)) {
            return;
        }
        DB::table($table)->where('payload', 'like', '%'.class_basename(GenererFichiersCarte::class).'%')->get(['id', 'payload'])
            ->filter(function (object $echec) use ($carte) {
                $commande = @unserialize((string) data_get(json_decode($echec->payload, true), 'data.command'), ['allowed_classes' => [GenererFichiersCarte::class]]);

                return $commande instanceof GenererFichiersCarte && $commande->carteId === $carte->id;
            })
            ->each(fn (object $echec) => DB::table($table)->where('id', $echec->id)->delete());
    }

    /**
     * Carte courante et active sans ses fichiers : génération demandée en tâche de fond (une seule par version, verrou
     * d'unicité du job). Rien en cas d'échec définitif : seule une relance du back-office relance la génération.
     */
    public function demander(Carte $carte): void
    {
        if ($carte->courante && $carte->statut === StatutCarte::Active && $carte->numero && ! $carte->fichiersEnEchec() && ! $this->prets($carte)) {
            GenererFichiersCarte::dispatch($carte->id, $carte->version);
        }
    }
}
