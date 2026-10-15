<?php

namespace Tests\Feature;

use App\Contracts\PaymentProvider;
use App\Enums\Role;
use App\Models\AuditLog;
use App\Models\MoyenPaiement;
use App\Models\Paiement;
use App\Models\User;
use Database\Seeders\ComptesDemoSeeder;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

/**
 * Verrous de production : aucun compte de démonstration (mot de passe = adresse e-mail), jamais de paiement simulé,
 * et « raaga:verifier-production » (appelé par deploy.sh) qui arrête le déploiement au moindre point manquant.
 */
class VerrousProductionTest extends TestCase
{
    use RefreshDatabase;

    /** Configuration complète de production : comptes de destination, opérateur réel (doublure). */
    private function productionPrete(): void
    {
        config([
            'raaga.demo' => false,
            'raaga.paiement.fournisseur' => 'operateur_test',
            'raaga.compte_sequestre' => ['titulaire' => 'Raaga SA', 'banque' => 'Banque A', 'numero' => 'BF00 1'],
            'raaga.compte_location' => ['titulaire' => 'Raaga SA location', 'banque' => 'Banque B', 'numero' => 'BF00 2'],
        ]);
        $this->app->instance(PaymentProvider::class, new class implements PaymentProvider
        {
            public function estSimule(): bool
            {
                return false;
            }

            public function initier(Paiement $paiement): void {}
        });
    }

    public function test_le_seeder_de_demo_refuse_de_s_executer_en_production(): void
    {
        $this->app['env'] = 'production';

        $this->assertThrows(fn () => $this->app->make(ComptesDemoSeeder::class)->run(), RuntimeException::class, 'comptes de démonstration');
        // Le seeder complet installe le reste (rôles, référentiels, moyens de paiement), sans aucun compte de démonstration.
        $this->app->make(DatabaseSeeder::class)->setContainer($this->app)->__invoke();
        $this->assertSame(0, User::query()->where('email', 'like', '%@raaga.test')->count());
        $this->assertGreaterThan(0, MoyenPaiement::query()->count());
    }

    public function test_la_commande_verifie_puis_desactive_les_comptes_de_demo(): void
    {
        $this->seed(ComptesDemoSeeder::class);
        $reel = User::factory()->role(Role::Validateur)->create(['email' => 'mariam.traore@raaga.bf', 'password' => 'un-vrai-mot-de-passe']);
        $copie = User::factory()->role(Role::Regulateur)->create(['email' => 'clarisse@raaga.bf', 'password' => 'clarisse@raaga.bf']); // mot de passe = e-mail
        $demo = User::query()->where('email', 'like', '%@raaga.test')->count();

        $this->artisan('raaga:comptes-demo')->assertFailed()->expectsOutputToContain('Relancez avec --desactiver');
        $this->assertSame($demo + 1, User::query()->where('actif', true)->where(fn ($q) => $q->where('email', 'like', '%@raaga.test')->orWhere('id', $copie->id))->count());

        $this->artisan('raaga:comptes-demo --desactiver')->assertSuccessful()->expectsOutputToContain(($demo + 1).' compte(s) de démonstration désactivé(s)');
        $this->assertSame(0, User::query()->where('actif', true)->where('email', 'like', '%@raaga.test')->count());
        $this->assertFalse($copie->fresh()->actif);
        $this->assertTrue($reel->fresh()->actif);
        $this->assertSame($demo + 1, AuditLog::query()->where('action', 'compte.demo_desactive')->count());
        $this->assertSame($demo + 1, User::query()->where('actif', false)->count()); // désactivés, jamais supprimés
        $this->artisan('raaga:comptes-demo')->assertSuccessful()->expectsOutputToContain('Aucun compte de démonstration actif');
    }

    public function test_paiement_simule_refuse_en_production(): void
    {
        $this->app['env'] = 'production';
        config(['raaga.paiement.fournisseur' => null]);

        $this->assertThrows(fn () => app(PaymentProvider::class), RuntimeException::class, 'paiement simulé');
        $this->artisan('raaga:verifier-production')->assertFailed()
            ->expectsOutputToContain('Aucun opérateur de paiement réel configuré');
    }

    public function test_verifier_production_liste_chaque_point_manquant(): void
    {
        $this->seed(ComptesDemoSeeder::class);
        config(['raaga.demo' => true, 'raaga.paiement.fournisseur' => 'simule', 'raaga.compte_sequestre' => [], 'raaga.compte_location' => ['titulaire' => 'Raaga SA']]);

        $commande = $this->artisan('raaga:verifier-production')->assertFailed()
            ->expectsOutputToContain('RAAGA_DEMO=true')
            ->expectsOutputToContain('compte(s) de démonstration actif(s)')
            ->expectsOutputToContain('Aucun opérateur de paiement réel configuré');
        foreach (['RAAGA_SEQUESTRE_TITULAIRE', 'RAAGA_SEQUESTRE_BANQUE', 'RAAGA_SEQUESTRE_NUMERO', 'RAAGA_COMPTE_LOCATION_BANQUE', 'RAAGA_COMPTE_LOCATION_NUMERO'] as $variable) {
            $commande->expectsOutputToContain('Variable manquante : '.$variable.'.');
        }
        $commande->doesntExpectOutputToContain('Variable manquante : RAAGA_COMPTE_LOCATION_TITULAIRE.');
    }

    public function test_verifier_production_reussit_quand_tout_est_pret(): void
    {
        $this->seed(ComptesDemoSeeder::class);
        $this->artisan('raaga:comptes-demo --desactiver')->assertSuccessful();
        $this->productionPrete();

        $this->artisan('raaga:verifier-production')->assertSuccessful()->expectsOutputToContain('opérateur de paiement réel');
    }

    public function test_deploy_sh_arrete_le_deploiement_si_la_verification_echoue(): void
    {
        $script = file_get_contents(base_path('deploy.sh'));

        $this->assertStringContainsString('set -euo pipefail', $script);
        $this->assertStringContainsString('php artisan raaga:verifier-production', $script);
        // Vérifié avant la maintenance et les migrations : un échec laisse la version en place.
        $this->assertLessThan(strpos($script, 'php artisan down'), strpos($script, 'php artisan raaga:verifier-production'));
        $this->assertLessThan(strpos($script, 'php artisan migrate'), strpos($script, 'php artisan raaga:verifier-production'));
    }
}
