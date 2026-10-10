<?php

namespace Tests\Feature;

use App\Support\VerrouEnvironnement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Tests\TestCase;

class VerrouEnvironnementTest extends TestCase
{
    use RefreshDatabase;

    public function test_l_application_refuse_de_demarrer_en_production_avec_les_donnees_de_demonstration(): void
    {
        Log::spy();

        try {
            VerrouEnvironnement::verifier('production', true);
            $this->fail('Le démarrage aurait dû être refusé.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('RAAGA_DEMO=true est interdit avec APP_ENV=production', $e->getMessage());
        }

        Log::shouldHaveReceived('critical')->once();
    }

    public function test_hors_production_ou_sans_demonstration_le_demarrage_est_normal(): void
    {
        VerrouEnvironnement::verifier('production', false);
        VerrouEnvironnement::verifier('local', true);
        VerrouEnvironnement::verifier('testing', true);

        $this->addToAssertionCount(3);
    }

    public function test_le_bandeau_de_demonstration_n_apparait_qu_en_demonstration(): void
    {
        config(['raaga.demo' => true]);
        $this->get('/')->assertOk()->assertSee('Environnement de démonstration — données fictives');

        config(['raaga.demo' => false]);
        $this->get('/')->assertOk()->assertDontSee('Environnement de démonstration');
    }

    public function test_aucune_donnee_fictive_en_production_meme_si_la_configuration_le_demande(): void
    {
        config(['raaga.demo' => true]);
        $this->app['env'] = 'production';

        $this->assertFalse(VerrouEnvironnement::demonstration());
        $this->get('/')->assertOk()->assertDontSee('1 284 650 000')->assertDontSee('Environnement de démonstration');
    }
}
