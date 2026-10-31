<?php

namespace App\Console\Commands;

use App\Enums\StatutCarte;
use App\Models\Carte;
use App\Services\Cartes\FichiersCarte;
use App\Services\Cartes\ReglagesCarteManquants;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Support : régénère tout de suite (sans passer par la file d'attente) les fichiers PNG et PDF d'une carte, ou de toutes
 * les cartes courantes et actives. --manquants : seulement les cartes sans fichiers à leur version (cartes émises avant
 * la génération en tâche de fond, génération en échec). Fichiers régénérés : l'échec de la carte est effacé et ses
 * générations en échec sont retirées de la table des tâches en échec (contrôlée par raaga:verifier-production).
 */
class FichiersCartes extends Command
{
    protected $signature = 'raaga:cartes-fichiers {numero? : Numéro de la carte (RG-C-000123)} {--toutes : Toutes les cartes courantes et actives} {--manquants : Seulement les cartes sans fichiers à leur version}';

    protected $description = 'Régénère les fichiers (PNG recto, PNG verso, PDF) d’une carte Raaga ou de toutes les cartes actives';

    public function handle(FichiersCarte $fichiers): int
    {
        $numero = $this->argument('numero');
        if (! $numero && ! $this->option('toutes')) {
            $this->error('Indiquez le numéro d’une carte (RG-C-000123) ou --toutes.');

            return self::FAILURE;
        }

        $requete = Carte::query()->courantes()->where('statut', StatutCarte::Active)->whereNotNull('numero')->orderBy('id');
        if ($numero) {
            $requete->where('numero', mb_strtoupper(trim((string) $numero)));
        }
        $cartes = $requete->get();
        if ($numero && $cartes->isEmpty()) {
            $this->error('Carte '.$numero.' introuvable, ou ni courante ni active.');

            return self::FAILURE;
        }

        $bilan = ['generees' => 0, 'deja' => 0, 'echecs' => 0];
        foreach ($cartes as $carte) {
            if ($this->option('manquants') && $fichiers->prets($carte)) {
                $bilan['deja']++;

                continue;
            }
            try {
                $fichiers->generer($carte);
                $bilan['generees']++;
                $this->line('✓ '.$carte->numero.' (version '.$carte->version.') : fichiers générés.');
            } catch (ReglagesCarteManquants $e) {
                $bilan['echecs']++;
                $this->error('✗ '.$carte->numero.' : '.$e->getMessage());
            } catch (Throwable $e) {
                $bilan['echecs']++;
                Log::error('Fichiers de la carte non générés (raaga:cartes-fichiers).', ['carte' => $carte->numero, 'version' => $carte->version, 'erreur' => $e->getMessage()]);
                $this->error('✗ '.$carte->numero.' : '.$e->getMessage());
            }
        }

        $this->info("Cartes traitées : {$bilan['generees']} ; déjà prêtes : {$bilan['deja']} ; en échec : {$bilan['echecs']}.");

        return $bilan['echecs'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
