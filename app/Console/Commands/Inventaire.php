<?php

namespace App\Console\Commands;

use App\Support\GardeFouBase;
use App\Support\Inventaire\BlocsAuto;
use App\Support\Inventaire\Generateur;
use App\Support\Inventaire\Masquage;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use RuntimeException;

/**
 * Inventaires locaux du front-office et du back-office (docs/INVENTAIRE-*.md, jamais versionnés ni déployés) :
 * régénère seulement les parties automatiques, encadrées par <!-- AUTO:debut nom --> et <!-- AUTO:fin nom -->, et
 * l'en-tête (date, heure, commit). Les parties rédigées à la main sont conservées.
 *
 * Réservée au poste de développement : refusée si APP_ENV n'est pas « local », avant tout accès à la base
 * (GardeFouBase). Lancée chaque jour à 13 h par le Planificateur de tâches du poste et après chaque git pull ou merge
 * (hook post-merge local), jamais sur le serveur.
 */
class Inventaire extends Command
{
    protected $signature = 'raaga:inventaire {--dossier= : Dossier des fichiers d’inventaire (par défaut docs/)}';

    protected $description = 'Régénère les parties automatiques des inventaires locaux du front-office et du back-office (poste local seulement).';

    /** Fichier => titre (pour un fichier encore absent) et méthode du générateur. */
    public const FICHIERS = [
        'INVENTAIRE-FRONT-OFFICE.md' => ['# Inventaire du front-office Raaga', 'frontOffice'],
        'INVENTAIRE-BACK-OFFICE.md' => ['# Inventaire du back-office Raaga', 'backOffice'],
    ];

    public function handle(Generateur $generateur): int
    {
        // Premier geste, avant toute requête : hors poste local, rien ne s'exécute.
        try {
            GardeFouBase::exigerPosteLocal($this->getName() ?? 'raaga:inventaire');
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $dossier = rtrim((string) ($this->option('dossier') ?: base_path('docs')), '/\\');
        File::ensureDirectoryExists($dossier);
        $commit = $this->commit();
        $horodatage = now()->format('d/m/Y').' à '.now()->format('H:i');

        foreach (self::FICHIERS as $fichier => [$titre, $methode]) {
            $chemin = $dossier.DIRECTORY_SEPARATOR.$fichier;
            $document = File::exists($chemin) ? File::get($chemin) : $titre."\n";
            $blocs = ['entete' => "> Parties automatiques régénérées le {$horodatage}, commit {$commit}, par « php artisan raaga:inventaire ». "
                .'Fichier local : jamais commité ni déployé. Les parties hors balises AUTO sont rédigées à la main.'];
            foreach ($generateur->{$methode}() as $nom => $contenu) {
                $blocs[$nom] = Masquage::texte($contenu);
            }
            File::put($chemin, BlocsAuto::remplacer($document, $blocs));
            $this->line("✓ {$fichier} : ".(count($blocs) - 1).' partie(s) automatique(s) régénérée(s).');
        }
        $this->info("Inventaires à jour (commit {$commit}).");

        return self::SUCCESS;
    }

    private function commit(): string
    {
        $resultat = Process::path(base_path())->run(['git', 'rev-parse', '--short', 'HEAD']);

        return $resultat->successful() && trim($resultat->output()) !== '' ? trim($resultat->output()) : 'inconnu';
    }
}
