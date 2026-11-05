<?php

namespace Tests\Feature;

use App\Support\OrdreMigrations;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Ordre des migrations : les noms servent à l'ordre, pas au calendrier. Une migration datée avant la plus récente est
 * signalée, sur la base (non exécutée alors qu'une plus récente l'est) comme dans le dépôt (ajoutée avec un nom
 * antérieur à la plus récente du dernier commit).
 */
class OrdreMigrationsTest extends TestCase
{
    use RefreshDatabase;

    public function test_une_migration_datee_avant_la_plus_recente_est_signalee(): void
    {
        $fichiers = ['2026_12_01_000000_a', '2026_12_02_000000_b', '2026_11_15_000000_oubliee', '2026_12_03_000000_nouvelle'];
        $executees = ['2026_12_01_000000_a', '2026_12_02_000000_b'];

        // Non exécutée et nommée avant la plus récente exécutée : signalée ; nommée après : normale.
        $this->assertSame(['2026_11_15_000000_oubliee'], OrdreMigrations::enRetard($fichiers, $executees));
        $this->assertSame([], OrdreMigrations::enRetard($fichiers, []));
        // Dépôt : ajoutée avec un nom antérieur à la plus récente du dernier commit.
        $this->assertSame(['2026_11_15_000000_oubliee'], OrdreMigrations::ajouteesEnRetard($executees, $fichiers));
    }

    public function test_le_depot_ne_contient_aucune_migration_datee_avant_la_plus_recente(): void
    {
        // Base de test migrée à neuf : rien en retard ; dépôt comparé au dernier commit (git absent : rien à comparer).
        $this->assertSame([], OrdreMigrations::enRetard(OrdreMigrations::fichiers(), OrdreMigrations::executees()));
        $this->assertSame([], OrdreMigrations::ajouteesEnRetard(), 'Migration ajoutée avec un nom antérieur à la plus récente du dernier commit.');
        $this->artisan('raaga:migrations-ordre')->assertSuccessful()->expectsOutputToContain('Ordre des migrations correct');
    }
}
