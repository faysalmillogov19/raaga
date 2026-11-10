<?php

namespace Tests\Feature\Compte;

use App\Contracts\SmsProvider;
use App\Enums\Role;
use App\Enums\StatutDocumentKyc;
use App\Enums\StatutKyc;
use App\Enums\TypeDocumentKyc;
use App\Models\KycDocument;
use App\Models\User;
use App\Services\Boutiques\Formalisation;
use Database\Seeders\CommunesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Support\Boutiques;
use Tests\Support\DossierKyc;
use Tests\Support\FakeSmsProvider;
use Tests\TestCase;

/**
 * Lot G0 (10/11/2026) : une personne physique pas encore formalisée dépose son IFU et son attestation à tout moment dans
 * « Vérifier mon identité » ; la réservation d'une boutique ne demande plus l'IFU.
 */
class IfuApresDepotTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        $this->app->instance(SmsProvider::class, new FakeSmsProvider);
    }

    private function citoyenSansIfu(StatutKyc $statut = StatutKyc::Valide): User
    {
        $user = User::factory()->role(Role::Citoyen)->create(['statut_kyc' => $statut, 'telephone' => '+22670004444', 'sexe' => 'F', 'lieu_naissance' => 'Ouagadougou']);
        DossierKyc::complet($user, '03040602618004501');

        return $user;
    }

    public function test_ifu_depose_apres_le_premier_depot_puis_valide(): void
    {
        $user = $this->citoyenSansIfu();

        $this->actingAs($user)->get(route('espace.verification'))->assertOk()
            ->assertSee('data-ifu-formalisation', false)->assertSee('Votre IFU')->assertSee('name="attestation_ifu"', false);

        $this->actingAs($user)->post(route('espace.verification.ifu'), ['numero_ifu' => '0001 2345 a'])->assertSessionHasErrors('attestation_ifu');
        $this->actingAs($user)->post(route('espace.verification.ifu'), ['numero_ifu' => '12', 'attestation_ifu' => UploadedFile::fake()->create('ifu.pdf', 50, 'application/pdf')])
            ->assertSessionHasErrors('numero_ifu');

        $this->actingAs($user)->post(route('espace.verification.ifu'), ['numero_ifu' => '0001 2345 a', 'attestation_ifu' => UploadedFile::fake()->create('ifu.pdf', 50, 'application/pdf')])
            ->assertSessionHasNoErrors()->assertRedirect(route('espace.verification').'#ifu');
        $user->refresh();
        $this->assertSame(['00012345A', StatutKyc::EnAttente], [$user->ifu, $user->statut_kyc]);
        $attestation = KycDocument::query()->where('user_id', $user->id)->where('type', TypeDocumentKyc::Ifu)->sole();
        $this->assertSame(['00012345A', StatutDocumentKyc::EnAttente], [$attestation->numero, $attestation->statut]);
        $this->assertFalse(Formalisation::formalise($user));
        $this->actingAs($user)->get(route('espace.verification'))->assertSee('Attestation IFU en cours de vérification.');

        // Attestation validée : formalisé, le formulaire disparaît.
        $attestation->forceFill(['statut' => StatutDocumentKyc::Valide])->save();
        $this->assertTrue(Formalisation::formalise($user->fresh()));
        $this->actingAs($user->fresh())->get(route('espace.verification'))->assertDontSee('data-ifu-formalisation', false);
        $this->actingAs($user->fresh())->post(route('espace.verification.ifu'), ['numero_ifu' => '00012345A'])->assertNotFound();
    }

    public function test_pas_de_formulaire_au_premier_depot_ni_pour_une_personne_morale(): void
    {
        $nouveau = User::factory()->role(Role::Citoyen)->create(['statut_kyc' => StatutKyc::NonSoumis]);
        $this->actingAs($nouveau)->get(route('espace.verification'))->assertOk()->assertDontSee('data-ifu-formalisation', false);

        $morale = User::factory()->role(Role::Citoyen)->create(['statut_kyc' => StatutKyc::Valide, 'type_titulaire' => 'MORALE', 'raison_sociale' => 'Société Test', 'ifu' => '00098765A']);
        $this->actingAs($morale)->get(route('espace.verification'))->assertOk()->assertDontSee('data-ifu-formalisation', false);
    }

    public function test_la_reservation_ne_demande_plus_l_ifu(): void
    {
        $this->seed(CommunesSeeder::class);
        Boutiques::campagneOuverte();
        $user = $this->citoyenSansIfu();
        $boutique = Boutiques::boutique('A', 1);

        $this->actingAs($user)->get(route('boutiques.boutique', $boutique->code))->assertOk()
            ->assertSee('data-formulaire-reservation', false)->assertDontSee('data-ifu-reservation', false)->assertDontSee('name="attestation_ifu"', false);
        // Sans IFU : préréservation acceptée, récépissé de préinscription.
        $this->actingAs($user)->post(route('boutiques.reserver', $boutique->code), Boutiques::donneesCommercant())->assertSessionHasNoErrors();
        $this->assertTrue(Formalisation::enCours($user->fresh()));
    }
}
