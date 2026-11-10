<?php

namespace App\Console\Commands;

use App\Support\GardeFouBase;
use App\Support\Guides\CapturesBoutique;
use App\Support\Guides\Guides as CatalogueGuides;
use App\Support\Guides\Pilote;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * Rubrique « Guides » (10/11/2026) : refait les captures des parcours (poste de développement, données fictives,
 * simulateur de paiement masqué) et régénère les pages statiques public/guides/. Les captures sont écrites dans un
 * dossier temporaire et ne remplacent les anciennes que si tout le parcours a réussi ; une étape impossible à rejouer
 * (écran changé) arrête la commande en la nommant. Jamais en production.
 */
class Guides extends Command
{
    protected $signature = 'raaga:guides {--sans-captures : Régénère seulement les pages statiques, avec les captures existantes} {--port=8765 : Port du serveur local}';

    protected $description = 'Refait les captures des guides pas à pas et régénère les pages statiques public/guides/ (poste local seulement).';

    public function handle(): int
    {
        try {
            GardeFouBase::exigerPosteLocal('raaga:guides');
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $base = 'http://127.0.0.1:'.(int) $this->option('port');
        $serveur = new Process([PHP_BINARY, 'artisan', 'serve', '--host=127.0.0.1', '--port='.(int) $this->option('port')], base_path());
        $serveur->setTimeout(null);
        $serveur->start();

        try {
            $this->attendre($base);
            if (! $this->option('sans-captures')) {
                $this->captures($base);
            }
            $this->pages($base);
        } catch (Throwable $e) {
            $this->error('Guides non régénérés : '.$e->getMessage());

            return self::FAILURE;
        } finally {
            $serveur->stop(3);
        }

        $this->info('Guides régénérés dans public/guides/.');

        return self::SUCCESS;
    }

    private function attendre(string $base): void
    {
        for ($i = 0; $i < 60; $i++) {
            try {
                if (Http::timeout(2)->get($base.'/_guides')->successful()) {
                    return;
                }
            } catch (Throwable) {
            }
            usleep(500000);
        }

        throw new RuntimeException('le serveur local ne répond pas sur '.$base.'.');
    }

    /** Captures du guide boutique dans un dossier temporaire, copiées dans public/guides/boutique/ si tout a réussi. */
    private function captures(string $base): void
    {
        $temporaire = storage_path('framework/guides-captures/boutique');
        File::deleteDirectory($temporaire);
        $pilote = new Pilote($base, $temporaire);
        try {
            (new CapturesBoutique($pilote))->executer(fn (string $etape) => $this->line('  '.$etape));
        } catch (Throwable $e) {
            try {
                $pilote->fermer();
            } catch (Throwable) {
            }

            throw $e;
        }
        File::ensureDirectoryExists(CatalogueGuides::dossier('boutique'));
        File::copyDirectory($temporaire, CatalogueGuides::dossier('boutique'));
        File::deleteDirectory($temporaire);
    }

    /** Pages statiques : sommaire et guides, rendus par le serveur local puis enregistrés (adresses rendues relatives). */
    private function pages(string $base): void
    {
        foreach (CatalogueGuides::tous() as $guide) {
            if ($manquantes = CatalogueGuides::capturesManquantes($guide)) {
                throw new RuntimeException('captures manquantes pour « '.$guide['slug'].' » : '.implode(', ', $manquantes).'. Relancez sans --sans-captures.');
            }
        }
        $ecrire = function (string $source, string $fichier) use ($base) {
            $reponse = Http::timeout(30)->get($base.$source);
            if (! $reponse->successful()) {
                throw new RuntimeException('page '.$source.' indisponible ('.$reponse->status().').');
            }
            File::ensureDirectoryExists(dirname($fichier));
            // Page statique : adresses du serveur local rendues relatives, sans jeton de formulaire.
            $html = str_replace($base, '', $reponse->body());
            File::put($fichier, preg_replace('#<meta name="csrf-token"[^>]*>\s*#', '', $html));
            $this->line('  '.str_replace(public_path().DIRECTORY_SEPARATOR, 'public/', $fichier));
        };
        $ecrire('/_guides', CatalogueGuides::dossier().'/index.html');
        foreach (CatalogueGuides::ORDRE as $slug) {
            $ecrire('/_guides/'.$slug, CatalogueGuides::dossier($slug).'/index.html');
        }
    }
}
