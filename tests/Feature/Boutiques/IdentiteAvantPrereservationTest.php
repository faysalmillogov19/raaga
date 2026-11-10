<?php

namespace Tests\Feature\Boutiques;

use App\Contracts\SmsProvider;
use App\Enums\Role;
use App\Enums\StatutKyc;
use App\Models\Greffe;
use App\Models\InscriptionBoutique;
use App\Models\User;
use Database\Seeders\CommunesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\Support\Boutiques;
use Tests\Support\FakeSmsProvider;
use Tests\TestCase;

/**
 * Préréservation d'une boutique (10/11/2026) : demande de vérification d'identité faite d'abord (dossier complet déposé,
 * en attente ou validé, pièce non expirée) ; sinon renvoi vers « Vérifier mon identité », puis retour à la boutique.
 */
class IdentiteAvantPrereservationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        $this->app->instance(SmsProvider::class, new FakeSmsProvider);
        $this->seed(CommunesSeeder::class);
        Boutiques::campagneOuverte();
    }

    private function citoyen(StatutKyc $statut): User
    {
        $user = User::factory()->role(Role::Citoyen)->create(['statut_kyc' => $statut, 'telephone' => '+22670001111', 'sexe' => 'F', 'lieu_naissance' => 'Ouagadougou']);
        Boutiques::ifuVerifie($user);

        return $user;
    }

    private function reserverSansDossier(User $user, string $code): TestResponse
    {
        return $this->actingAs($user)->post(route('boutiques.reserver', $code), [
            'type_piece' => 'CNIB', 'numero_piece' => '03040602618004501', 'piece' => UploadedFile::fake()->image('piece.jpg'), ...Boutiques::donneesCommercant(),
        ]);
    }

    public function test_sans_demande_de_verification_renvoi_vers_la_verification_puis_retour(): void
    {
        $user = $this->citoyen(StatutKyc::NonSoumis);
        $code = Boutiques::boutique('A', 1)->code;

        // Page de la boutique : encadré et lien vers « Vérifier mon identité », pas de formulaire de réservation.
        $this->actingAs($user)->get(route('boutiques.boutique', $code))->assertOk()
            ->assertSee('data-identite-a-demander', false)->assertSee('Vérifiez d’abord votre identité', false)
            ->assertSee(route('espace.verification', ['retour' => $code]), false)->assertDontSee('data-formulaire-reservation', false);

        // Envoi direct refusé, rien n'est inscrit.
        $this->reserverSansDossier($user, $code)->assertSessionHasErrors('boutique');
        $this->assertSame(0, InscriptionBoutique::query()->count());

        // Dossier complet déposé depuis la boutique : retour à la boutique, préréservation possible.
        $this->actingAs($user)->get(route('espace.verification', ['retour' => $code]))->assertOk()->assertSee('name="retour" value="'.$code.'"', false);
        $photo = fn (string $nom) => UploadedFile::fake()->image($nom, 800, 500);
        $this->actingAs($user)->post(route('espace.verification.store'), [
            'retour' => $code, 'type_piece' => 'CNIB', 'recto' => $photo('recto.jpg'), 'verso' => $photo('verso.jpg'), 'selfie' => $photo('selfie.jpg'), 'ifu' => $photo('ifu.jpg'),
            'sexe' => 'F', 'lieu_naissance' => 'Ouagadougou', 'numero_piece' => '03040602618004501', 'piece_expire_le' => now()->addYears(5)->toDateString(),
            // IFU déjà connu : activité formalisée, RCCM demandé aussi (10/11/2026).
            'numero_ifu' => $user->ifu, 'rccm' => 'BF OUA 2024 A 01234', 'rccm_immatricule_le' => '2024-03-01',
            'rccm_greffe_id' => (string) Greffe::query()->where('code', 'OUAGADOUGOU')->value('id'), 'justificatif_rccm' => $photo('rccm.jpg'),
        ])->assertRedirect(route('boutiques.boutique', $code));
        $this->assertSame(StatutKyc::EnAttente, $user->fresh()->statut_kyc);

        $this->actingAs($user->fresh())->get(route('boutiques.boutique', $code))->assertOk()
            ->assertDontSee('data-identite-a-demander', false)->assertSee('data-formulaire-reservation', false)->assertSee('data-piece-compte', false);
        $this->actingAs($user->fresh())->post(route('boutiques.reserver', $code), Boutiques::donneesCommercant())->assertSessionHasNoErrors();
        $this->assertSame(1, InscriptionBoutique::query()->where('user_id', $user->id)->count());
    }

    public function test_dossier_rejete_ou_incomplet_a_corriger_d_abord(): void
    {
        $code = Boutiques::boutique('A', 1)->code;

        $rejete = $this->citoyen(StatutKyc::Rejete);
        $this->actingAs($rejete)->get(route('boutiques.boutique', $code))->assertOk()->assertSee('Votre dossier d’identité est à corriger.', false);
        $this->reserverSansDossier($rejete, $code)->assertSessionHasErrors('boutique');

        // En attente, mais incomplet (recto seul) : pas encore une demande faite.
        $incomplet = User::factory()->role(Role::Citoyen)->create(['statut_kyc' => StatutKyc::EnAttente, 'telephone' => '+22670002222']);
        $this->actingAs($incomplet)->get(route('boutiques.boutique', $code))->assertOk()->assertSee('data-identite-a-demander', false);

        // Pièce expirée, même avec une identité validée.
        $expiree = User::factory()->role(Role::Citoyen)->create(['statut_kyc' => StatutKyc::Valide, 'telephone' => '+22670003333', 'piece_expire_le' => now()->subDay()->toDateString()]);
        $this->actingAs($expiree)->get(route('boutiques.boutique', $code))->assertOk()->assertSee('Votre pièce d’identité a expiré.', false);
    }
}
