<?php

namespace Tests\Feature\Publique;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class PagesErreurTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach ([403, 419, 500, 503] as $code) {
            Route::get("/_test/erreur-{$code}", fn () => abort($code));
        }
        Route::get('/_test/exception', fn () => throw new \RuntimeException('boum'));
    }

    public function test_page_404_en_francais_avec_retour_accueil_et_aide(): void
    {
        $this->get('/page-qui-n-existe-pas')
            ->assertNotFound()
            ->assertSee('Page introuvable')
            ->assertSee('Retour à l’accueil')
            ->assertSee('href="'.url('/').'"', false)
            ->assertSee('href="'.url('/faq').'"', false)
            ->assertSee('<img src=', false);
    }

    public function test_pages_403_419_et_500(): void
    {
        $this->get('/_test/erreur-403')->assertForbidden()->assertSee('Accès refusé');
        $this->get('/_test/erreur-419')->assertStatus(419)->assertSee('Votre session a expiré. Reconnectez-vous pour continuer.');
        $this->get('/_test/erreur-500')->assertStatus(500)->assertSee('Une erreur est survenue');
    }

    public function test_une_exception_non_geree_n_expose_aucun_detail_technique(): void
    {
        config(['app.debug' => false]);

        $this->get('/_test/exception')
            ->assertStatus(500)
            ->assertSee('Une erreur est survenue')
            ->assertDontSee('boum')
            ->assertDontSee('RuntimeException');
    }

    public function test_page_503_reprend_le_message_de_maintenance_des_parametres(): void
    {
        $messages = plateforme()->section('messages');
        data_set($messages, 'modeMaintenance.message', 'Mise à jour des serveurs en cours.');
        plateforme()->enregistrer('messages', $messages, null);

        $this->get('/_test/erreur-503')
            ->assertStatus(503)
            ->assertSee('Site en maintenance')
            ->assertSee('Mise à jour des serveurs en cours.');
    }
}
