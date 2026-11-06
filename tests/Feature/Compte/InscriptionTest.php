<?php

namespace Tests\Feature\Compte;

use App\Contracts\SmsProvider;
use App\Enums\DocumentAccepte;
use App\Enums\Role;
use App\Enums\StatutKyc;
use App\Models\Acceptation;
use App\Models\AuditLog;
use App\Models\Commune;
use App\Models\Greffe;
use App\Models\User;
use Database\Seeders\CommunesSeeder;
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
        $this->seed(CommunesSeeder::class);
    }

    private function ouagadougou(): Commune
    {
        return Commune::query()->where('code', 'BF130004')->sole();
    }

    private function identite(array $modifs = []): array
    {
        return array_merge([
            'type_titulaire' => 'PHYSIQUE',
            'nom' => 'Ouédraogo',
            'prenoms' => 'Awa Marie',
            'date_naissance' => now()->subYears(30)->toDateString(),
            'ifu' => '0001 2345 a',
            'pays_residence' => 'BF',
            'commune_id' => (string) $this->ouagadougou()->id,
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
            ->assertSee('Munissez-vous de votre téléphone, de votre numéro IFU et de votre CNIB, CIB-AES ou passeport', false)
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
            ->assertSee('Pour pouvoir souscrire, il vous reste à faire vérifier votre identité (CNIB, CIB-AES ou passeport + selfie', false)
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

    public function test_l_ifu_est_obligatoire_pour_une_personne_morale_seulement(): void
    {
        $this->verifierNumero();
        $morale = fn (array $donnees = []) => $this->identite(['type_titulaire' => 'MORALE', 'raison_sociale' => 'Tissages du Faso SARL', 'forme_morale' => 'ENTREPRISE',
            'gerant_fonction' => 'Gérante', 'email' => 'gerance@tissages.bf', 'rccm' => 'BF OUA 2020 B 12345', 'rccm_immatricule_le' => '2020-03-02',
            'rccm_greffe_id' => (string) Greffe::query()->where('code', 'OUAGADOUGOU')->value('id'), ...$donnees]);

        $this->get('/inscription')->assertSee('name="ifu"', false)->assertSee('Numéro IFU')->assertDontSee('Numéro Raaga (IFU)');
        foreach (['' => 'Saisissez un numéro IFU valide : 8 chiffres suivis d’une lettre.', '1234567A' => 'Saisissez un numéro IFU valide : 8 chiffres suivis d’une lettre.'] as $saisie => $message) {
            $this->from('/inscription')->post('/inscription/identite', $morale(['ifu' => $saisie]))->assertSessionHasErrors(['ifu' => $message]);
        }
        User::factory()->role(Role::Citoyen)->create(['ifu' => '00012345A']);
        $this->from('/inscription')->post('/inscription/identite', $morale())
            ->assertSessionHasErrors(['ifu' => 'Ce numéro IFU est déjà utilisé par un autre compte.']);

        // Saisie normalisée : majuscules, sans espace, tiret ni le mot « IFU ».
        $this->post('/inscription/identite', $morale(['ifu' => 'IFU 0009-8765 z']))->assertSessionHasNoErrors();
        $this->assertSame('00098765Z', User::query()->where('telephone', '+22676543210')->value('ifu'));
    }

    public function test_une_personne_physique_s_inscrit_sans_ifu(): void
    {
        $this->verifierNumero();

        $this->post('/inscription/identite', $this->identite(['ifu' => '']))->assertSessionHasNoErrors();
        $this->assertNull(User::query()->where('telephone', '+22676543210')->value('ifu'));
    }

    public function test_la_commune_est_exigee_au_burkina_et_pas_dans_la_diaspora(): void
    {
        $this->verifierNumero();

        $this->from('/inscription')->post('/inscription/identite', $this->identite(['commune_id' => '']))
            ->assertSessionHasErrors(['commune_id' => 'Choisissez votre commune de résidence.']);
        $this->from('/inscription')->post('/inscription/identite', $this->identite(['commune_id' => '999999']))
            ->assertSessionHasErrors('commune_id');

        $this->post('/inscription/identite', $this->identite(['pays_residence' => 'FR', 'commune_id' => '']))
            ->assertSessionHasNoErrors();

        $user = User::query()->where('telephone', '+22676543210')->sole();
        $this->assertSame(['FR', null], [$user->pays_residence, $user->commune_id]);
    }

    public function test_la_commune_est_proposee_par_province_et_reprend_sa_region(): void
    {
        $this->verifierNumero();

        $this->get('/inscription')->assertOk()
            ->assertSee('Commune de résidence')
            ->assertSee('<optgroup label="Kadiogo (Kadiogo)">', false)
            // Commune : champ de saisie avec suggestions sur la liste d'origine (06/11/2026).
            ->assertSee('data-choix-commune', false)->assertSee('id="commune_id-saisie"', false)->assertSee('role="combobox"', false)
            ->assertSee('<option value="'.$this->ouagadougou()->id.'" >Ouagadougou</option>', false)
            ->assertDontSee('Boucle du Mouhoun');

        $this->post('/inscription/identite', $this->identite())->assertSessionHasNoErrors();

        $user = User::query()->where('telephone', '+22676543210')->sole();
        $this->assertSame([$this->ouagadougou()->id, 'Kadiogo'], [$user->commune_id, $user->commune->province->region->nom]);
        $this->assertSame('Ouagadougou', $user->commune->nom);
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
