<?php

namespace App\Console\Commands;

use App\Support\OrdreMigrations;
use Illuminate\Console\Command;

/**
 * Signale les migrations datées avant la plus récente : non exécutées alors qu'une migration plus récente l'est déjà sur
 * la base, ou ajoutées au dossier avec un nom antérieur à la plus récente du dernier commit. Les noms servent à l'ordre,
 * pas au calendrier : renommer la migration avec une date postérieure à la plus récente.
 */
class MigrationsOrdre extends Command
{
    protected $signature = 'raaga:migrations-ordre';

    protected $description = 'Signale une migration datée avant la plus récente du dossier database/migrations';

    public function handle(): int
    {
        $fichiers = OrdreMigrations::fichiers();
        $base = OrdreMigrations::enRetard($fichiers, OrdreMigrations::executees());
        $depot = OrdreMigrations::ajouteesEnRetard(fichiers: $fichiers);
        $derniere = end($fichiers) ?: '';

        foreach (array_unique([...$base, ...$depot]) as $nom) {
            $this->error("Migration datée avant la plus récente ({$derniere}) : {$nom}. Renommez-la avec une date postérieure, même si elle dépasse la date du jour.");
        }
        if ($base === [] && $depot === []) {
            $this->info('Ordre des migrations correct : la plus récente est '.$derniere.'.');

            return self::SUCCESS;
        }

        return self::FAILURE;
    }
}
