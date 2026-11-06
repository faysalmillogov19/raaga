<?php

namespace Tests\Feature\Compte;

use App\Contracts\SmsProvider;
use App\Enums\Role;
use App\Enums\StatutKyc;
use App\Models\User;
use App\Support\Nip;
use Database\Seeders\CommunesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\Support\Boutiques;
use Tests\Support\FakeSmsProvider;
use Tests\TestCase;

/**
 * NIP de la CNIB et de la CIB-AES (06/11/2026) : 17 chiffres à la place du numéro de la pièce, espaces, points et tirets
 * acceptés à la saisie ; le passeport garde son numéro. Libellé « NIP de la CNIB », texte indicatif, contrôle dans le
 * navigateur et sur le serveur.
 */
class NipTest extends TestCase
{
    use RefreshDatabase;

    private const MESSAGE_CNIB = 'Le NIP de la CNIB compte 17 chiffres. Recopiez-le tel qu’il est inscrit sur la pièce.';

    private User $citoyen;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        $this->app->instance(SmsProvider::class, new FakeSmsProvider);
        $this->citoyen = User::factory()->role(Role::Citoyen)->create(['statut_kyc' => StatutKyc::NonSoumis]);
    }

    private function deposer(string $type, string $numero): TestResponse
    {
        $photo = fn (string $nom) => UploadedFile::fake()->image($nom, 800, 500);

        return $this->actingAs($this->citoyen)->post('/espace/verification', [
            'type_piece' => $type, 'recto' => $photo('recto.jpg'), 'verso' => $photo('verso.jpg'), 'selfie' => $photo('selfie.jpg'),
            'sexe' => 'F', 'lieu_naissance' => 'Ouagadougou', 'numero_piece' => $numero, 'piece_expire_le' => now()->addYears(5)->toDateString(),
        ]);
    }

    public function test_regle_17_chiffres_pour_la_cnib_et_la_cib_aes_seulement(): void
    {
        $this->assertTrue(Nip::valide('0304 0602 6180 0450 1'));
        $this->assertTrue(Nip::valide('03040602618-00450.1'));
        $this->assertFalse(Nip::valide('B12345678'));
        $this->assertFalse(Nip::valide('0304060261800450'));
        $this->assertFalse(Nip::valide('030406026180045012'));
    }

    public function test_verification_d_identite_libelle_indication_et_controle(): void
    {
        $this->actingAs($this->citoyen)->get('/espace/verification')->assertOk()
            ->assertSee('data-champ-nip', false)->assertSee('NIP de la CNIB')->assertSee('NIP de la CIB-AES')
            ->assertSee('Les 17 chiffres du NIP inscrits sur la CNIB')->assertSee(self::MESSAGE_CNIB);

        // Ancien numéro de carte : refusé, avec le message du NIP.
        $this->deposer('CNIB', 'B12345678')->assertSessionHasErrors(['numero_piece' => self::MESSAGE_CNIB]);
        $this->deposer('CIB_AES', 'AES 001')->assertSessionHasErrors('numero_piece');
        $this->assertSame(0, $this->citoyen->kycDocuments()->count());

        // NIP avec espaces : accepté, enregistré sans les espaces.
        $this->deposer('CNIB', '0304 0602 6180 0450 1')->assertSessionHasNoErrors();
        $this->assertSame('03040602618004501', $this->citoyen->fresh()->numero_piece);
    }

    public function test_le_passeport_garde_son_numero(): void
    {
        $this->deposer('PASSEPORT', 'P0012345')->assertSessionHasNoErrors();
        $this->assertSame('P0012345', $this->citoyen->fresh()->numero_piece);
    }

    public function test_ancien_numero_cnib_du_compte_non_repris_dans_le_formulaire(): void
    {
        $this->citoyen->forceFill(['numero_piece' => 'B12345678'])->save();
        $this->actingAs($this->citoyen)->get('/espace/verification')->assertOk()->assertDontSee('value="B12345678"', false);
    }

    public function test_reservation_d_une_boutique_exige_le_nip(): void
    {
        $this->seed(CommunesSeeder::class);
        Boutiques::campagneOuverte();
        $citoyen = User::factory()->role(Role::Citoyen)->avecIfu()->create(['statut_kyc' => StatutKyc::Valide, 'telephone' => '+22670009999', 'piece_expire_le' => now()->addYears(5)->toDateString()]);
        Boutiques::ifuVerifie($citoyen);
        $this->actingAs($citoyen)->post(route('boutiques.reserver', Boutiques::boutique('A', 1)->code), [
            'type_piece' => 'CNIB', 'numero_piece' => 'B1234567', 'piece' => UploadedFile::fake()->image('piece.jpg'), ...Boutiques::donneesCommercant(),
        ])->assertSessionHasErrors(['numero_piece' => self::MESSAGE_CNIB]);
        Boutiques::reserver($this, $citoyen, Boutiques::boutique('A', 1), '0304 0602 6180 0450 1')->assertSessionHasNoErrors();
    }
}
