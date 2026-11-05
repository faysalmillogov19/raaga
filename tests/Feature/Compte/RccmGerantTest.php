<?php

namespace Tests\Feature\Compte;

use App\Contracts\SmsProvider;
use App\Enums\Role;
use App\Enums\StatutDocumentKyc;
use App\Enums\StatutKyc;
use App\Enums\TypeDocumentKyc;
use App\Enums\TypeTitulaire;
use App\Models\AuditLog;
use App\Models\Commune;
use App\Models\Greffe;
use App\Models\User;
use App\Services\Kyc\RegistreCommerce;
use App\Services\Kyc\VerificationIdentite;
use Database\Seeders\CommunesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Support\FakeSmsProvider;
use Tests\TestCase;

/**
 * Réunion du 05/11/2026, lot 1 : personne morale (raison sociale, forme, IFU, gérant = titulaire du compte avec sa
 * fonction et son adresse électronique, RCCM obligatoire) ; RCCM (numéro, date d'immatriculation, greffe, justificatif)
 * facultatif pour une personne physique, modifiable à tout moment dans « Vérifier mon identité », jamais sur la carte.
 */
class RccmGerantTest extends TestCase
{
    use RefreshDatabase;

    private FakeSmsProvider $sms;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sms = new FakeSmsProvider;
        $this->app->instance(SmsProvider::class, $this->sms);
        $this->seed(CommunesSeeder::class);
        Storage::fake('local');
    }

    private function identite(array $modifs = []): array
    {
        return array_merge(['type_titulaire' => 'MORALE', 'raison_sociale' => 'Tissages du Faso SARL', 'forme_morale' => 'ENTREPRISE', 'ifu' => '00012345A',
            'nom' => 'Kaboré', 'prenoms' => 'Issa', 'date_naissance' => now()->subYears(40)->toDateString(), 'pays_residence' => 'BF',
            'commune_id' => (string) Commune::query()->where('code', 'BF130004')->value('id'), 'conditions' => '1',
            'gerant_fonction' => 'Directeur général', 'email' => 'Direction@Tissages.bf',
            'rccm' => 'bf  oua 2020 b 12345', 'rccm_immatricule_le' => '2020-03-02', 'rccm_greffe_id' => (string) Greffe::query()->where('code', 'OUAGADOUGOU')->value('id')], $modifs);
    }

    private function verifierNumero(): void
    {
        $this->post('/inscription/telephone', ['telephone' => '76 54 32 10'])->assertRedirect('/inscription');
        $this->post('/inscription/code', ['code' => $this->sms->dernierCode()])->assertRedirect('/inscription');
    }

    public function test_personne_morale_gerant_et_rccm_obligatoires_a_l_inscription(): void
    {
        $this->verifierNumero();
        $this->get('/inscription')->assertOk()->assertSee('data-gerant', false)->assertSee('data-rccm', false)
            ->assertSee('Fonction du gérant')->assertSee('Numéro RCCM')->assertSee('Greffe d’immatriculation', false);

        $this->from('/inscription')->post('/inscription/identite', $this->identite(['gerant_fonction' => '', 'email' => '', 'rccm' => '', 'rccm_immatricule_le' => '', 'rccm_greffe_id' => '']))
            ->assertSessionHasErrors(['gerant_fonction', 'email', 'rccm', 'rccm_immatricule_le', 'rccm_greffe_id']);
        $this->from('/inscription')->post('/inscription/identite', $this->identite(['rccm_immatricule_le' => now()->addDay()->toDateString()]))
            ->assertSessionHasErrors(['rccm_immatricule_le' => 'Indiquez la date d’immatriculation, qui ne peut pas être dans le futur.']);

        $this->post('/inscription/identite', $this->identite())->assertSessionHasNoErrors();
        $user = User::query()->where('telephone', '+22676543210')->sole();
        $this->assertSame([TypeTitulaire::Morale, 'Directeur général', 'direction@tissages.bf', 'BF OUA 2020 B 12345', '2020-03-02', 'Tribunal de commerce de Ouagadougou'],
            [$user->type_titulaire, $user->gerant_fonction, $user->email, $user->rccm, $user->rccm_immatricule_le->toDateString(), Greffe::libellePour($user)]);
        // Justificatif RCCM attendu dans le dossier d'identité.
        $this->assertContains('rccm', app(VerificationIdentite::class)->manquants($user));
    }

    public function test_personne_physique_sans_gerant_ni_rccm_a_l_inscription(): void
    {
        $this->verifierNumero();
        $this->post('/inscription/identite', $this->identite(['type_titulaire' => 'PHYSIQUE', 'ifu' => '', 'gerant_fonction' => '', 'email' => '', 'rccm' => '',
            'rccm_immatricule_le' => '', 'rccm_greffe_id' => '']))->assertSessionHasNoErrors();
        $user = User::query()->where('telephone', '+22676543210')->sole();
        $this->assertSame([null, null, null], [$user->rccm, $user->gerant_fonction, $user->email]);
        $this->assertNotContains('rccm', app(VerificationIdentite::class)->manquants($user));
    }

    public function test_rccm_facultatif_d_une_personne_physique_modifiable_a_tout_moment(): void
    {
        $user = User::factory()->role(Role::Citoyen)->create(['statut_kyc' => StatutKyc::Valide]);
        $this->actingAs($user)->get(route('espace.verification'))->assertOk()->assertSee('data-rccm', false)->assertSee('Facultatif. Si vous êtes immatriculé', false);

        // Les trois champs ensemble ; numéro nouveau : justificatif exigé.
        $this->actingAs($user)->post(route('espace.verification.rccm'), ['rccm' => 'BF BBY 2019 A 777'])->assertSessionHasErrors(['rccm_immatricule_le', 'rccm_greffe_id']);
        $champs = ['rccm' => 'BF BBY 2019 A 777', 'rccm_immatricule_le' => '2019-01-15', 'rccm_greffe_id' => (string) Greffe::query()->where('code', 'BOBO_DIOULASSO')->value('id')];
        $this->actingAs($user)->post(route('espace.verification.rccm'), $champs)->assertSessionHasErrors('document');
        $this->actingAs($user)->post(route('espace.verification.rccm'), [...$champs, 'document' => UploadedFile::fake()->create('rccm.pdf', 100, 'application/pdf')])
            ->assertSessionHasNoErrors()->assertRedirect(route('espace.verification').'#rccm');
        $user->refresh();
        $this->assertSame('BF BBY 2019 A 777', $user->rccm);
        $this->assertSame([StatutDocumentKyc::EnAttente, 'BF BBY 2019 A 777'], [RegistreCommerce::justificatif($user)->statut, RegistreCommerce::justificatif($user)->numero]);
        // Dossier déjà validé : repasse « en attente » pour l'ADMIN ; le justificatif fait partie du dossier.
        $this->assertSame(StatutKyc::EnAttente, $user->statut_kyc);
        $this->assertTrue(app(VerificationIdentite::class)->dossier($user)->has('rccm'));
        $this->assertSame(1, AuditLog::query()->where('action', 'rccm.modifie')->count());

        // Même numéro, autre greffe : pas de nouveau justificatif exigé. Champs vidés : RCCM retiré.
        $this->actingAs($user)->post(route('espace.verification.rccm'), [...$champs, 'rccm_greffe_id' => (string) Greffe::query()->where('code', 'OUAGADOUGOU')->value('id')])->assertSessionHasNoErrors();
        $this->actingAs($user)->post(route('espace.verification.rccm'), ['rccm' => '', 'rccm_immatricule_le' => '', 'rccm_greffe_id' => ''])->assertSessionHasNoErrors();
        $this->assertNull($user->fresh()->rccm);
        $this->assertFalse(VerificationIdentite::avecRccm($user->fresh()));
    }

    public function test_rccm_obligatoire_pour_une_personne_morale_et_unique(): void
    {
        $morale = User::factory()->role(Role::Citoyen)->create(['type_titulaire' => TypeTitulaire::Morale, 'raison_sociale' => 'Société X', 'forme_morale' => 'ENTREPRISE',
            'ifu' => '00011111A', 'rccm' => 'BF OUA 2020 B 1', 'rccm_immatricule_le' => '2020-01-01', 'rccm_greffe_id' => Greffe::query()->where('code', 'OUAGADOUGOU')->value('id')]);
        $this->actingAs($morale)->get(route('espace.verification'))->assertSee('Obligatoire pour une personne morale.', false);
        $this->actingAs($morale)->post(route('espace.verification.rccm'), ['rccm' => '', 'rccm_immatricule_le' => '', 'rccm_greffe_id' => ''])
            ->assertSessionHasErrors(['rccm', 'rccm_immatricule_le', 'rccm_greffe_id']);
        $this->assertSame('BF OUA 2020 B 1', $morale->fresh()->rccm);

        $autre = User::factory()->role(Role::Citoyen)->create();
        $this->actingAs($autre)->post(route('espace.verification.rccm'), ['rccm' => 'BF OUA 2020 B 1', 'rccm_immatricule_le' => '2020-01-01', 'rccm_greffe_id' => (string) Greffe::query()->where('code', 'OUAGADOUGOU')->value('id'),
            'document' => UploadedFile::fake()->create('rccm.pdf', 100, 'application/pdf')])->assertSessionHasErrors(['rccm' => 'Ce numéro RCCM est déjà enregistré sur un autre compte.']);
    }

    public function test_le_rccm_n_est_jamais_sur_la_carte(): void
    {
        $this->assertStringNotContainsStringIgnoringCase('rccm', (string) file_get_contents(resource_path('views/cartes/_faces.blade.php')));
        $this->assertStringNotContainsString('rccm', (string) file_get_contents(app_path('Services/Cartes/Cartes.php')));
        $this->assertStringNotContainsString('rccm', (string) file_get_contents(app_path('Services/Cartes/RenduCarte.php')));
        $this->assertSame(TypeDocumentKyc::Rccm, TypeDocumentKyc::from('RCCM'));
    }
}
