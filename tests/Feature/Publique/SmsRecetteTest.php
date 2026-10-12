<?php

namespace Tests\Feature\Publique;

use App\Enums\Role;
use App\Models\User;
use App\Services\Sms\LogSmsProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SmsRecetteTest extends TestCase
{
    use RefreshDatabase;

    private string $fichier;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fichier = sys_get_temp_dir().'/raaga-sms-recette-'.uniqid().'.jsonl';
        config(['raaga.sms.fichier_recette' => $this->fichier, 'raaga.sms.pilote' => 'log', 'raaga.sms.journal_recette' => null]);
    }

    protected function tearDown(): void
    {
        @unlink($this->fichier);

        parent::tearDown();
    }

    public function test_le_code_de_connexion_s_affiche_sur_la_page_des_sms_de_recette(): void
    {
        User::factory()->role(Role::Citoyen)->create(['telephone' => '+22670123456']);

        $this->get('/recette/sms')->assertOk()->assertSee('SMS de recette')->assertSee('Aucun SMS pour le moment.');

        $this->post('/connexion/telephone', ['telephone' => '70 12 34 56'])->assertRedirect(route('connexion'));

        // L'écran de saisie du code renvoie vers la page.
        $this->get('/connexion')->assertOk()->assertSee('href="'.route('recette.sms').'"', false);

        $messages = $this->getJson('/recette/sms')->assertOk()->assertHeader('Cache-Control', 'no-store, private')->json();
        $this->assertCount(1, $messages);
        $this->assertSame('+226 70 •• •• 56', $messages[0]['telephone']);
        $this->assertMatchesRegularExpression('/^\d{6}$/', $messages[0]['code']);
        $this->assertStringContainsString($messages[0]['code'], $messages[0]['message']);

        // Le code affiché est bien celui qui permet de se connecter.
        $this->post('/connexion/code', ['code' => $messages[0]['code']]);
        $this->assertAuthenticated();
    }

    public function test_le_plus_recent_en_premier_et_journal_borne(): void
    {
        $sms = new LogSmsProvider;
        $sms->envoyer('+22670000001', 'Premier : code 111111');
        $sms->envoyer('+22670000002', 'Second : code 222222');

        $this->assertSame(['222222', '111111'], array_column($this->getJson('/recette/sms')->json(), 'code'));
        $this->assertCount(2, LogSmsProvider::recents());
    }

    public function test_jamais_en_production_ni_quand_la_page_est_coupee(): void
    {
        config(['raaga.sms.journal_recette' => false]);
        $this->get('/recette/sms')->assertNotFound();

        config(['raaga.sms.journal_recette' => true]);
        $this->app['env'] = 'production';
        $this->get('/recette/sms')->assertNotFound();

        // En production, rien n'est écrit dans le journal de recette et aucun lien n'est affiché.
        (new LogSmsProvider)->envoyer('+22670000001', 'Code 123456');
        $this->assertFileDoesNotExist($this->fichier);
        User::factory()->role(Role::Citoyen)->create(['telephone' => '+22670123456']);
        $this->post('/connexion/telephone', ['telephone' => '70 12 34 56']);
        $this->get('/connexion')->assertOk()->assertDontSee(route('recette.sms'));
    }
}
