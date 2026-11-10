<?php

namespace Tests\Feature\Compte;

use App\Contracts\SmsProvider;
use App\Enums\Role;
use App\Enums\StatutKyc;
use App\Enums\TypeDocumentKyc;
use App\Models\Greffe;
use App\Models\KycDocument;
use App\Models\User;
use App\Services\Kyc\VerificationIdentite;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\Support\FakeSmsProvider;
use Tests\TestCase;

/**
 * Identification avant la préréservation (10/11/2026) : une personne physique, au premier dépôt, indique si son activité
 * est formalisée ; si oui, IFU (numéro et attestation) et RCCM (numéro, date, greffe, justificatif) sont demandés.
 */
class FormalisationIdentificationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        $this->app->instance(SmsProvider::class, new FakeSmsProvider);
    }

    private function deposer(User $user, array $donnees = []): TestResponse
    {
        $photo = fn (string $nom) => UploadedFile::fake()->image($nom, 800, 500);

        return $this->actingAs($user)->post(route('espace.verification.store'), [
            'type_piece' => 'CNIB', 'recto' => $photo('recto.jpg'), 'verso' => $photo('verso.jpg'), 'selfie' => $photo('selfie.jpg'),
            'sexe' => 'F', 'lieu_naissance' => 'Ouagadougou', 'numero_piece' => '03040602618004501', 'piece_expire_le' => now()->addYears(5)->toDateString(),
            ...$donnees,
        ]);
    }

    private function formalisation(): array
    {
        return ['numero_ifu' => '0001 2345 a', 'ifu' => UploadedFile::fake()->create('ifu.pdf', 50, 'application/pdf'),
            'rccm' => 'bf oua 2024 a 01234', 'rccm_immatricule_le' => '2024-03-01',
            'rccm_greffe_id' => (string) Greffe::query()->where('code', 'OUAGADOUGOU')->value('id'),
            'justificatif_rccm' => UploadedFile::fake()->create('rccm.pdf', 50, 'application/pdf')];
    }

    public function test_question_posee_au_premier_depot_d_une_personne_physique(): void
    {
        $user = User::factory()->role(Role::Citoyen)->create(['statut_kyc' => StatutKyc::NonSoumis]);

        $this->actingAs($user)->get(route('espace.verification'))->assertOk()
            ->assertSee('data-formalisation', false)->assertSee('Votre activité est-elle déjà formalisée ?')
            ->assertSee('Oui, j’ai mon IFU et mon RCCM', false)->assertSee('Non, pas encore')
            ->assertSee('name="justificatif_rccm"', false)
            // Le formulaire RCCM séparé n'apparaît pas au premier dépôt : le bloc de formalisation le remplace.
            ->assertDontSee('action="'.route('espace.verification.rccm').'"', false);

        $this->deposer($user)->assertSessionHasErrors(['formalisee' => 'Indiquez si votre activité est déjà formalisée.']);
        $this->assertSame(0, $user->kycDocuments()->count());
    }

    public function test_non_formalisee_ni_ifu_ni_rccm_demandes(): void
    {
        $user = User::factory()->role(Role::Citoyen)->create(['statut_kyc' => StatutKyc::NonSoumis]);

        $this->deposer($user, ['formalisee' => 'non'])->assertSessionHasNoErrors();
        $user->refresh();
        $this->assertSame([StatutKyc::EnAttente, null, null], [$user->statut_kyc, $user->ifu, $user->rccm]);
        $this->assertSame(0, $user->kycDocuments()->whereIn('type', [TypeDocumentKyc::Ifu, TypeDocumentKyc::Rccm])->count());
        $this->assertTrue(app(VerificationIdentite::class)->demandeFaite($user));
    }

    public function test_formalisee_ifu_et_rccm_obligatoires_puis_enregistres(): void
    {
        $user = User::factory()->role(Role::Citoyen)->create(['statut_kyc' => StatutKyc::NonSoumis]);

        $this->deposer($user, ['formalisee' => 'oui'])
            ->assertSessionHasErrors(['numero_ifu', 'ifu', 'rccm', 'rccm_immatricule_le', 'rccm_greffe_id', 'justificatif_rccm']);
        $this->assertSame(0, $user->kycDocuments()->count());

        $this->deposer($user, ['formalisee' => 'oui', ...$this->formalisation()])->assertSessionHasNoErrors();
        $user->refresh();
        $this->assertSame(['00012345A', 'BF OUA 2024 A 01234', '2024-03-01'], [$user->ifu, $user->rccm, $user->rccm_immatricule_le->toDateString()]);
        $this->assertSame('00012345A', KycDocument::query()->where('user_id', $user->id)->where('type', TypeDocumentKyc::Ifu)->sole()->numero);
        $this->assertSame('BF OUA 2024 A 01234', KycDocument::query()->where('user_id', $user->id)->where('type', TypeDocumentKyc::Rccm)->sole()->numero);
        $this->assertSame(StatutKyc::EnAttente, $user->statut_kyc);
        $this->assertTrue(app(VerificationIdentite::class)->demandeFaite($user));
    }

    public function test_ifu_deja_connu_formalisation_completee_d_office(): void
    {
        $user = User::factory()->role(Role::Citoyen)->create(['statut_kyc' => StatutKyc::NonSoumis, 'ifu' => '00012345A']);

        $this->actingAs($user)->get(route('espace.verification'))->assertOk()
            ->assertSee('Votre IFU ou votre RCCM est déjà indiqué : complétez les informations de formalisation.')
            ->assertSee('type="hidden" name="formalisee" value="oui"', false)->assertDontSee('data-formalisee="non"', false)
            ->assertSee('value="00012345A"', false);

        // « non » envoyé quand même : ignoré, le RCCM est demandé.
        $this->deposer($user, ['formalisee' => 'non'])->assertSessionHasErrors(['rccm', 'justificatif_rccm']);
    }

    public function test_personne_morale_sans_question(): void
    {
        $morale = User::factory()->role(Role::Citoyen)->create(['statut_kyc' => StatutKyc::NonSoumis, 'type_titulaire' => 'MORALE', 'raison_sociale' => 'Société Test', 'ifu' => '00098765A']);

        $this->actingAs($morale)->get(route('espace.verification'))->assertOk()->assertDontSee('data-formalisation', false);
    }
}
