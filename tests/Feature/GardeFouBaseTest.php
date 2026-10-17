<?php

namespace Tests\Feature;

use App\Support\GardeFouBase;
use RuntimeException;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * Protection de la base locale : tests et commandes --env=testing seulement sur une base « _test » ; commandes qui
 * effacent des données refusées en production et confirmées (nom de la base) ailleurs.
 */
class GardeFouBaseTest extends TestCase
{
    protected bool $seed = false;

    private function entree(array $parametres = [], ?string $reponse = null): ArrayInput
    {
        $entree = new ArrayInput($parametres);
        $entree->setInteractive($reponse !== null);
        if ($reponse !== null) {
            $flux = fopen('php://memory', 'r+');
            fwrite($flux, $reponse.PHP_EOL);
            rewind($flux);
            $entree->setStream($flux);
        }

        return $entree;
    }

    private function commande(string $commande, ArrayInput $entree): ?string
    {
        try {
            GardeFouBase::avantCommande($commande, $entree, new BufferedOutput);

            return null;
        } catch (RuntimeException $e) {
            return $e->getMessage();
        }
    }

    public function test_noms_de_base_de_test(): void
    {
        foreach (['raaga_test', 'raaga_test_test_3', 'raaga_test_12'] as $nom) {
            $this->assertTrue(GardeFouBase::estBaseDeTest($nom), $nom);
        }
        foreach (['raaga', 'raaga_testing', 'test', 'raaga_test_prod', '', null] as $nom) {
            $this->assertFalse(GardeFouBase::estBaseDeTest($nom), (string) $nom);
        }
        // Les tests eux-mêmes tournent sur une base de test.
        $this->assertTrue(GardeFouBase::estBaseDeTest(GardeFouBase::baseCourante()));
    }

    public function test_environnement_de_test_refuse_une_base_qui_n_est_pas_de_test(): void
    {
        config(['database.connections.'.config('database.default').'.database' => 'raaga']);
        $this->assertThrows(fn () => GardeFouBase::exigerBaseDeTest(), RuntimeException::class, '« raaga » ne se termine pas par « _test »');
        // Toute commande en environnement de test, même anodine.
        $this->assertStringContainsString('Base refusée pour les tests', (string) $this->commande('about', $this->entree()));
        $this->assertStringContainsString('Base refusée pour les tests', (string) $this->commande('migrate:fresh', $this->entree()));
    }

    public function test_commande_lancee_avec_env_testing_sur_la_base_locale_refusee(): void
    {
        // Vrai lancement d'Artisan : la base (variable d'environnement) n'est pas une base de test → refus au démarrage,
        // avant tout accès à la base.
        $processus = new Process([PHP_BINARY, 'artisan', 'migrate:fresh', '--env=testing', '--force'], base_path(), ['DB_DATABASE' => 'raaga_protegee']);
        $processus->run();
        $this->assertNotSame(0, $processus->getExitCode());
        $this->assertStringContainsString('ne se termine pas par « _test »', $processus->getOutput().$processus->getErrorOutput());
    }

    public function test_production_refuse_les_commandes_destructrices(): void
    {
        $this->app['env'] = 'production';
        config(['database.connections.'.config('database.default').'.database' => 'raaga']);

        foreach (['migrate:fresh', 'migrate:refresh', 'migrate:reset', 'db:wipe', 'db:seed'] as $commande) {
            $this->assertStringContainsString('Commande refusée en production', (string) $this->commande($commande, $this->entree(['--force' => true], 'raaga')), $commande);
        }
        $this->assertNotNull($this->commande('db:seed', $this->entree(['--class' => 'ComptesDemoSeeder', '--force' => true])));
        // Seeder idempotent des référentiels lancé par deploy.sh : admis.
        $this->assertNull($this->commande('db:seed', $this->entree(['--class' => 'ReferentielsSeeder', '--force' => true])));
        // Les autres commandes (migrate, about…) ne sont pas concernées.
        $this->assertNull($this->commande('migrate', $this->entree(['--force' => true])));
    }

    public function test_hors_production_confirmation_explicite_par_le_nom_de_la_base(): void
    {
        $this->app['env'] = 'local';
        config(['database.connections.'.config('database.default').'.database' => 'raaga']);

        // Sans interaction possible : refus.
        $this->assertStringContainsString('demande une confirmation', (string) $this->commande('db:wipe', $this->entree()));
        // Mauvais nom : annulée.
        $this->assertStringContainsString('Commande annulée', (string) $this->commande('migrate:fresh', $this->entree([], 'oui')));
        // Nom exact de la base : confirmée.
        $this->assertNull($this->commande('migrate:fresh', $this->entree([], 'raaga')));
        $this->assertNull($this->commande('db:seed', $this->entree([], 'raaga')));
        $this->assertNull($this->commande('migrate', $this->entree()));
    }

    public function test_env_testing_example_pointe_sur_la_base_de_test(): void
    {
        $exemple = file_get_contents(base_path('.env.testing.example'));
        $this->assertMatchesRegularExpression('/^APP_ENV=testing$/m', $exemple);
        $this->assertMatchesRegularExpression('/^DB_DATABASE=raaga_test$/m', $exemple);
        $this->assertStringContainsString('.env.testing', file_get_contents(base_path('.gitignore')));
    }
}
