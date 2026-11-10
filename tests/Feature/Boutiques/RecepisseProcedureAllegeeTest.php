<?php

namespace Tests\Feature\Boutiques;

use App\Contracts\SmsProvider;
use App\Enums\Role;
use App\Enums\StatutDocumentKyc;
use App\Enums\StatutKyc;
use App\Enums\TypeDocumentKyc;
use App\Models\KycDocument;
use App\Models\Recepisse;
use App\Models\User;
use App\Services\Boutiques\Formalisation;
use App\Services\Plateforme\ParametresPlateforme;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Support\Boutiques;
use Tests\Support\DossierKyc;
use Tests\Support\FakeSmsProvider;
use Tests\TestCase;

/**
 * Lot G1 (10/11/2026) : récépissé dès que l'IFU ou le RCCM manque ; il permet de demander l'un et l'autre selon la
 * procédure allégée ; la démarche s'affiche dans « Vérifier mon identité », « Mes boutiques » et sur le récépissé.
 */
class RecepisseProcedureAllegeeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        $this->app->instance(SmsProvider::class, new FakeSmsProvider);
        Boutiques::campagneOuverte();
    }

    private function citoyen(array $attributs = []): User
    {
        static $n = 0;
        $n++;
        $user = User::factory()->role(Role::Citoyen)->create(['statut_kyc' => StatutKyc::Valide, 'telephone' => '+2267005'.sprintf('%04d', $n), ...$attributs]);
        DossierKyc::complet($user, '0304060261800'.sprintf('%04d', $n));

        return $user;
    }

    private function rccmValide(User $user): void
    {
        $user->forceFill(['rccm' => 'BF BOB 2023 A 01234', 'rccm_immatricule_le' => '2023-05-02', 'rccm_greffe_id' => 2])->save();
        KycDocument::query()->create(['user_id' => $user->id, 'type' => TypeDocumentKyc::Rccm, 'chemin_fichier' => 'kyc/rccm.pdf', 'mime' => 'application/pdf',
            'taille' => 1000, 'empreinte_sha256' => str_repeat('0', 64), 'statut' => StatutDocumentKyc::Valide, 'televerse_par' => $user->id, 'numero' => 'BF BOB 2023 A 01234']);
    }

    public function test_recepisse_des_que_l_ifu_ou_le_rccm_manque(): void
    {
        // IFU validé, sans RCCM : récépissé, il reste le RCCM.
        $sansRccm = $this->citoyen();
        Boutiques::ifuVerifie($sansRccm);
        Boutiques::reserver($this, $sansRccm, Boutiques::boutique('A', 1))->assertSessionHasNoErrors();
        $this->assertSame(1, Recepisse::query()->where('user_id', $sansRccm->id)->count());
        $this->assertTrue(Formalisation::enCours($sansRccm->fresh()));
        $this->assertSame('votre RCCM', Formalisation::libelleAObtenir($sansRccm->fresh()));
        $this->actingAs($sansRccm)->get(route('espace', ['onglet' => 'boutiques']))->assertOk()
            ->assertSee('Avec votre récépissé, demandez votre RCCM selon la procédure allégée.', false);

        // RCCM validé ensuite : formalisation complète, le délai est clos.
        $this->rccmValide($sansRccm->fresh());
        $this->assertTrue(Formalisation::complete($sansRccm->fresh()));
        $this->assertFalse(Formalisation::enCours($sansRccm->fresh()));

        // IFU et RCCM présents : pas de récépissé.
        $formalise = $this->citoyen();
        Boutiques::ifuVerifie($formalise);
        $this->rccmValide($formalise);
        Boutiques::reserver($this, $formalise, Boutiques::boutique('A', 2))->assertSessionHasNoErrors();
        $this->assertSame(0, Recepisse::query()->where('user_id', $formalise->id)->count());
    }

    public function test_demarche_affichee_a_la_reponse_non_avec_le_lieu_s_il_est_regle(): void
    {
        $nouveau = User::factory()->role(Role::Citoyen)->create(['statut_kyc' => StatutKyc::NonSoumis]);
        $this->actingAs($nouveau)->get(route('espace.verification'))->assertOk()
            ->assertSee('data-demarche-formalisation', false)->assertSee('Démarche à suivre')
            ->assertSee('Présentez ce récépissé avec votre pièce d’identité pour demander votre IFU et votre RCCM selon la procédure allégée.', false)
            ->assertDontSee('Où présenter le récépissé', false);

        app(ParametresPlateforme::class)->enregistrer('messages', ['lieuFormalisation' => 'Centre de formalités, Ouagadougou'], $nouveau);
        $this->actingAs($nouveau)->get(route('espace.verification'))->assertSee('Où présenter le récépissé :', false)->assertSee('Centre de formalités, Ouagadougou');
    }

    public function test_texte_du_recepisse_avec_la_procedure_allegee(): void
    {
        $user = $this->citoyen(['nom' => 'SANOU', 'prenoms' => 'Ali']);
        Boutiques::reserver($this, $user, Boutiques::boutique('A', 1))->assertSessionHasNoErrors();
        $recepisse = Recepisse::query()->where('user_id', $user->id)->sole();

        $html = view('documents.recepisse-preinscription', ['recepisse' => $recepisse->load('user', 'boutique.site', 'boutique.type'), 'qr' => 'data:image/png;base64,'])->render();
        $this->assertStringContainsString('pour déposer son RCCM sur la plateforme. Il lui permet de demander son RCCM selon la procédure allégée. Il ne remplace aucune pièce officielle.', html_entity_decode($html, ENT_QUOTES));
        $this->assertStringContainsString('Démarche à suivre', $html);
    }
}
