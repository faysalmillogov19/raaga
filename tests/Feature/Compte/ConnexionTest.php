<?php

namespace Tests\Feature\Compte;

use App\Contracts\SmsProvider;
use App\Enums\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\FakeSmsProvider;
use Tests\TestCase;

class ConnexionTest extends TestCase
{
    use RefreshDatabase;

    private FakeSmsProvider $sms;

    protected function setUp(): void
    {
        parent::setUp();

        $this->sms = new FakeSmsProvider;
        $this->app->instance(SmsProvider::class, $this->sms);
    }

    private function citoyen(): User
    {
        return User::factory()->role(Role::Citoyen)->create([
            'telephone' => '+22670123456',
            'prenoms' => 'Awa Marie',
            'nom' => 'OUEDRAOGO',
        ]);
    }

    private function demanderCode(string $telephone = '70 12 34 56')
    {
        return $this->post('/connexion/telephone', ['telephone' => $telephone]);
    }

    public function test_la_page_de_connexion_s_affiche(): void
    {
        $this->get('/connexion')
            ->assertOk()
            ->assertSee('Se connecter')
            ->assertSee('Accédez à votre espace souscripteur.')
            ->assertSee('+226')
            ->assertSee('inputmode="tel"', false)
            ->assertSee('Recevoir mon code')
            ->assertSee('80 00 00 00')
            ->assertSee(route('inscription'));
    }

    public function test_un_numero_invalide_est_refuse(): void
    {
        $this->from('/connexion')->post('/connexion/telephone', ['telephone' => '12 34'])
            ->assertSessionHasErrors(['telephone' => 'Saisissez un numéro de téléphone burkinabè à 8 chiffres, par exemple 70 12 34 56.']);

        $this->assertSame([], $this->sms->envoyes);
    }

    public function test_un_numero_inconnu_propose_de_creer_un_compte(): void
    {
        $this->followingRedirects()->from('/connexion')->post('/connexion/telephone', ['telephone' => '70 00 00 01'])
            ->assertSee('Aucun compte n’est associé à ce numéro.', false)
            ->assertSee('Créer un compte');

        $this->assertSame([], $this->sms->envoyes);
    }

    public function test_un_code_est_envoye_puis_demande(): void
    {
        $this->citoyen();

        $this->demanderCode()->assertRedirect('/connexion');

        $this->assertCount(1, $this->sms->envoyes);
        $this->assertSame('+22670123456', $this->sms->envoyes[0]['telephone']);
        $this->assertMatchesRegularExpression('/^\d{6}$/', $this->sms->dernierCode());

        $this->get('/connexion')
            ->assertSeeText('Saisissez le code envoyé au +226 70 12 34 56')
            ->assertSee('Modifier le numéro')
            ->assertSee('Renvoyer le code')
            ->assertSee('class="otp', false);
    }

    public function test_le_bon_code_connecte_et_redirige_vers_l_espace(): void
    {
        $user = $this->citoyen();
        $this->demanderCode();

        $this->post('/connexion/code', ['code' => $this->sms->dernierCode()])
            ->assertRedirect(route('espace'));

        $this->assertAuthenticatedAs($user);
    }

    public function test_apres_connexion_on_revient_a_la_page_demandee(): void
    {
        $this->citoyen();

        $this->get('/espace/verification')->assertRedirect(route('connexion'));
        $this->demanderCode();

        $this->post('/connexion/code', ['code' => $this->sms->dernierCode()])
            ->assertRedirect('/espace/verification');
    }

    public function test_un_mauvais_code_affiche_un_message_neutre(): void
    {
        $this->citoyen();
        $this->demanderCode();

        $this->from('/connexion')->post('/connexion/code', ['code' => '000000'] + [])
            ->assertSessionHasErrors(['code' => 'Code incorrect ou expiré. Vérifiez le SMS reçu ou demandez un nouveau code.']);

        $this->assertGuest();
    }

    public function test_cinq_mauvais_codes_bloquent_quinze_minutes(): void
    {
        $this->citoyen();
        $this->demanderCode();
        $bonCode = $this->sms->dernierCode();
        $mauvais = $bonCode === '111111' ? '222222' : '111111';

        for ($i = 0; $i < 5; $i++) {
            $this->from('/connexion')->post('/connexion/code', ['code' => $mauvais]);
        }

        // Même le bon code est refusé pendant le blocage.
        $this->from('/connexion')->post('/connexion/code', ['code' => $bonCode])
            ->assertSessionHasErrors(['code' => 'Trop de tentatives. Pour votre sécurité, réessayez dans 15 minutes.']);
        $this->assertGuest();

        $this->travel(16)->minutes();
        $this->demanderCode();
        $this->post('/connexion/code', ['code' => $this->sms->dernierCode()])->assertRedirect(route('espace'));
        $this->assertAuthenticated();
    }

    public function test_un_code_expire_apres_cinq_minutes(): void
    {
        $this->citoyen();
        $this->demanderCode();

        $this->travel(6)->minutes();

        $this->from('/connexion')->post('/connexion/code', ['code' => $this->sms->dernierCode()])
            ->assertSessionHasErrors('code');
        $this->assertGuest();
    }

    public function test_un_code_ne_sert_qu_une_fois(): void
    {
        $user = $this->citoyen();
        $this->demanderCode();
        $code = $this->sms->dernierCode();

        $this->post('/connexion/code', ['code' => $code]);
        $this->post('/logout');

        $this->withSession(['connexion.telephone' => $user->telephone])
            ->from('/connexion')->post('/connexion/code', ['code' => $code])
            ->assertSessionHasErrors('code');
    }

    public function test_le_renvoi_est_possible_apres_45_secondes(): void
    {
        $this->citoyen();
        $this->demanderCode();

        $this->from('/connexion')->post('/connexion/renvoyer')
            ->assertSessionHasErrors(['code' => 'Patientez quelques secondes avant de demander un nouveau code.']);
        $this->assertCount(1, $this->sms->envoyes);

        $this->travel(46)->seconds();
        $this->from('/connexion')->post('/connexion/renvoyer')->assertSessionHasNoErrors();
        $this->assertCount(2, $this->sms->envoyes);
    }

    public function test_redemander_un_code_deja_utilise_avant_45_secondes_affiche_une_erreur(): void
    {
        $this->citoyen();
        $this->demanderCode();
        $this->post('/connexion/code', ['code' => $this->sms->dernierCode()]);
        $this->post('/logout');

        // Le code précédent a servi : on ne doit pas arriver sur un écran de code sans code valable.
        $this->from('/connexion')->post('/connexion/telephone', ['telephone' => '70 12 34 56'])
            ->assertSessionHasErrors(['telephone' => 'Patientez quelques secondes avant de demander un nouveau code.']);

        $this->travel(46)->seconds();
        $this->demanderCode()->assertSessionHasNoErrors()->assertRedirect('/connexion');
        $this->assertCount(2, $this->sms->envoyes);
    }

    public function test_modifier_le_numero_revient_a_la_saisie(): void
    {
        $this->citoyen();
        $this->demanderCode();

        $this->get('/connexion/modifier')->assertRedirect('/connexion');
        $this->get('/connexion')->assertSee('Recevoir mon code')->assertSee('70 12 34 56');
    }

    public function test_l_en_tete_affiche_le_prenom_une_fois_connecte(): void
    {
        $this->get('/')->assertSee(route('connexion'));

        $this->actingAs($this->citoyen())
            ->get('/')
            ->assertSee('Awa')
            ->assertSee('Se déconnecter');
    }

    public function test_un_citoyen_deja_connecte_est_envoye_vers_son_espace(): void
    {
        $this->actingAs($this->citoyen())->get('/connexion')->assertRedirect(route('espace'));
    }
}
