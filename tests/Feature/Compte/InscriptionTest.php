<?php

namespace Tests\Feature\Compte;

use App\Contracts\SmsProvider;
use App\Enums\DocumentAccepte;
use App\Enums\Role;
use App\Enums\StatutKyc;
use App\Models\Acceptation;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\FakeSmsProvider;
use Tests\TestCase;

class InscriptionTest extends TestCase
{
    use RefreshDatabase;

    private FakeSmsProvider $sms;

    protected function setUp(): void
    {
        parent::setUp();

        $this->sms = new FakeSmsProvider;
        $this->app->instance(SmsProvider::class, $this->sms);
    }

    private function identite(array $modifs = []): array
    {
        return array_merge([
            'nom' => 'Ouédraogo',
            'prenoms' => 'Awa Marie',
            'date_naissance' => now()->subYears(30)->toDateString(),
            'pays_residence' => 'BF',
            'region' => 'Centre',
            'conditions' => '1',
        ], $modifs);
    }

    private function verifierNumero(string $telephone = '76 54 32 10'): void
    {
        $this->post('/inscription/telephone', ['telephone' => $telephone])->assertRedirect('/inscription');
        $this->post('/inscription/code', ['code' => $this->sms->dernierCode()])->assertRedirect('/inscription');
    }

    public function test_la_premiere_etape_s_affiche(): void
    {
        $this->get('/inscription')
            ->assertOk()
            ->assertSee('Créer mon compte')
            ->assertSee('Munissez-vous de votre téléphone et de votre CNIB ou passeport.')
            ->assertSee('class="stepper"', false)
            ->assertSeeInOrder(['Téléphone', 'Code', 'Identité'])
            ->assertSee('Vous avez déjà un compte ?');
    }

    public function test_un_numero_deja_inscrit_propose_de_se_connecter(): void
    {
        User::factory()->create(['telephone' => '+22676543210']);

        $this->followingRedirects()->from('/inscription')->post('/inscription/telephone', ['telephone' => '76 54 32 10'])
            ->assertSee('Ce numéro a déjà un compte.')
            ->assertSee(route('connexion'));

        $this->assertSame([], $this->sms->envoyes);
    }

    public function test_le_parcours_complet_cree_un_compte_citoyen(): void
    {
        $this->verifierNumero();

        $this->get('/inscription')
            ->assertSee('Ces informations doivent correspondre exactement à votre pièce d’identité.', false)
            ->assertSee('Burkinabè de la diaspora');

        $this->post('/inscription/identite', $this->identite())->assertRedirect('/inscription');

        $user = User::query()->where('telephone', '+22676543210')->firstOrFail();
        $this->assertAuthenticatedAs($user);
        $this->assertSame(Role::Citoyen, $user->codeRole());
        $this->assertSame('OUÉDRAOGO', $user->nom);
        $this->assertSame('Awa Marie OUÉDRAOGO', $user->name);
        $this->assertSame('BF', $user->nationalite);
        $this->assertSame(StatutKyc::NonSoumis, $user->statut_kyc);

        $acceptation = Acceptation::query()->where('user_id', $user->id)->firstOrFail();
        $this->assertSame(DocumentAccepte::ConditionsUtilisation, $acceptation->document);
        $this->assertNotNull($acceptation->accepte_le);

        $audit = AuditLog::query()->where('action', 'compte.cree')->firstOrFail();
        $this->assertStringNotContainsString('76543210', json_encode($audit->nouvelle_valeur));

        $this->get('/inscription')
            ->assertSee('Votre compte est créé')
            ->assertSee('Pour pouvoir souscrire, il vous reste à faire vérifier votre identité (CNIB ou passeport + selfie).')
            ->assertSee(route('espace.verification'))
            ->assertSee('Plus tard');
    }

    public function test_il_faut_avoir_au_moins_18_ans(): void
    {
        $this->verifierNumero();

        $this->from('/inscription')->post('/inscription/identite', $this->identite([
            'date_naissance' => now()->subYears(17)->toDateString(),
        ]))->assertSessionHasErrors(['date_naissance' => 'Vous devez avoir au moins 18 ans pour créer un compte.']);

        $this->assertGuest();
    }

    public function test_les_conditions_sont_obligatoires(): void
    {
        $this->verifierNumero();

        $donnees = $this->identite();
        unset($donnees['conditions']);

        $this->from('/inscription')->post('/inscription/identite', $donnees)
            ->assertSessionHasErrors(['conditions' => 'Vous devez accepter les conditions d’utilisation pour créer votre compte.']);
    }

    public function test_la_region_est_exigee_au_burkina_et_pas_dans_la_diaspora(): void
    {
        $this->verifierNumero();

        $this->from('/inscription')->post('/inscription/identite', $this->identite(['region' => '']))
            ->assertSessionHasErrors('region');

        $this->post('/inscription/identite', $this->identite(['pays_residence' => 'FR', 'region' => '']))
            ->assertSessionHasNoErrors();

        $this->assertSame('FR', User::query()->where('telephone', '+22676543210')->value('pays_residence'));
    }

    public function test_l_identite_exige_un_numero_verifie(): void
    {
        $this->post('/inscription/identite', $this->identite())
            ->assertRedirect('/inscription')
            ->assertSessionHasErrors('telephone');

        $this->assertDatabaseCount('users', 0);
    }

    public function test_le_retour_conserve_la_saisie(): void
    {
        $this->verifierNumero();

        $this->post('/inscription/retour', ['vers' => 'telephone', 'nom' => 'SAWADOGO', 'prenoms' => 'Ali'])
            ->assertRedirect('/inscription');

        $this->get('/inscription')->assertSee('Recevoir mon code')->assertSee('76 54 32 10');

        // Même numéro déjà vérifié : retour direct à l'identité, sans nouveau code.
        $this->post('/inscription/telephone', ['telephone' => '76 54 32 10'])->assertRedirect('/inscription');
        $this->assertCount(1, $this->sms->envoyes);
        $this->get('/inscription')->assertSee('value="SAWADOGO"', false)->assertSee('value="Ali"', false);
    }
}
