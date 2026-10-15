<?php

namespace Tests\Feature\Compte;

use App\Contracts\SmsProvider;
use App\Enums\Role;
use App\Enums\StatutCandidatureBoutique;
use App\Enums\StatutKyc;
use App\Enums\StatutPaiement;
use App\Models\CandidatureBoutique;
use App\Models\Paiement;
use App\Models\User;
use App\Services\Paiement\ServicePaiement;
use App\Support\Recu;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\FakeSmsProvider;
use Tests\TestCase;

class CautionBoutiqueTest extends TestCase
{
    use RefreshDatabase;

    private FakeSmsProvider $sms;

    private User $citoyen;

    private CandidatureBoutique $candidature;

    protected function setUp(): void
    {
        parent::setUp();

        $this->sms = new FakeSmsProvider;
        $this->app->instance(SmsProvider::class, $this->sms);

        $campagne = CandidatureBoutiqueTest::campagneOuverte();
        $this->citoyen = User::factory()->role(Role::Citoyen)->create(['statut_kyc' => StatutKyc::Valide, 'telephone' => '+22670123456']);
        $this->actingAs($this->citoyen)->post('/boutiques/candidater', [
            'categorie' => $campagne->categories()->where('libelle', 'Grande boutique')->value('id'),
            'engagements' => ['TIRAGE_SANS_GARANTIE', 'CAUTION_SANS_LIEN_ACTIONS', 'REGLEMENT_CAMPAGNE_BOUTIQUES', 'ORIGINE_LICITE_FONDS'],
        ])->assertRedirect();
        $this->candidature = CandidatureBoutique::sole();
    }

    private function url(string $suffixe = ''): string
    {
        return '/boutiques/candidatures/'.$this->candidature->reference.'/caution'.$suffixe;
    }

    public function test_la_caution_se_paie_avec_le_module_de_paiement_et_donne_un_recu(): void
    {
        $this->actingAs($this->citoyen)->get($this->url())->assertOk()
            ->assertSee('Paiement de la caution')
            ->assertSee('Paiement simulé, environnement de recette')
            ->assertSee('Grande boutique')->assertSee("500\u{00A0}000\u{00A0}FCFA")
            ->assertSee('Orange Money')->assertSee('Virement bancaire')->assertDontSee('Moov Money');

        $this->actingAs($this->citoyen)->post($this->url(), ['canal' => 'ORANGE_MONEY', 'telephone' => '70 12 34 56'])->assertRedirect($this->url());
        $paiement = Paiement::sole();
        $this->assertSame($this->candidature->id, $paiement->candidature_boutique_id);
        $this->assertNull($paiement->souscription_id);
        $this->assertSame([500_000, 0], [$paiement->montant, $paiement->frais]);

        $this->actingAs($this->citoyen)->getJson($this->url('/statut'))->assertExactJson(['objet' => 'RESERVEE', 'paiement' => 'EN_ATTENTE']);
        $this->actingAs($this->citoyen)->post($this->url('/simuler'), ['issue' => 'succes'])->assertRedirect($this->url());

        $this->assertSame(StatutCandidatureBoutique::Payee, $this->candidature->fresh()->statut);
        $this->assertNotNull($this->candidature->fresh()->payee_le);
        $this->assertSame(StatutPaiement::Confirme, $paiement->fresh()->statut);

        // SMS avec le numéro de reçu.
        $this->assertCount(1, $this->sms->envoyes);
        $this->assertStringContainsString(Recu::numero($paiement->fresh()), $this->sms->envoyes[0]['message']);
        $this->assertStringContainsString('caution reçue', $this->sms->envoyes[0]['message']);

        // Payée : l'écran de paiement renvoie au suivi, qui propose le reçu.
        $this->actingAs($this->citoyen)->get($this->url())->assertRedirect(route('boutiques.candidature', $this->candidature->reference));
        $this->actingAs($this->citoyen)->get(route('boutiques.candidature', $this->candidature->reference))->assertOk()
            ->assertSee('Caution payée, en attente du tirage')->assertSee('Télécharger le reçu de caution (PDF)');

        $html = view('documents.recu-caution', ['candidature' => $this->candidature->fresh()->load(['campagne', 'categorie', 'user']),
            'paiement' => $paiement->fresh(), 'numero' => Recu::numero($paiement->fresh()), 'pdf' => true])->render();
        foreach (['Reçu de caution', $this->candidature->reference, 'Grande boutique', "500\u{00A0}000\u{00A0}FCFA", 'L’achat d’actions ne donne aucun droit ni priorité pour obtenir une boutique.'] as $attendu) {
            $this->assertStringContainsString($attendu, $html);
        }
        $this->assertStringStartsWith('%PDF', $this->actingAs($this->citoyen)->get($this->url('/recu'))->assertOk()->assertHeader('Content-Type', 'application/pdf')->getContent());
    }

    public function test_confirmation_idempotente_et_echec(): void
    {
        $this->actingAs($this->citoyen)->post($this->url(), ['canal' => 'VIREMENT']);
        $this->actingAs($this->citoyen)->post($this->url('/simuler'), ['issue' => 'echec']);
        $this->assertSame(StatutCandidatureBoutique::Reservee, $this->candidature->fresh()->statut);
        $this->actingAs($this->citoyen)->get($this->url())->assertOk()->assertSee('Le paiement a échoué');

        $this->actingAs($this->citoyen)->post($this->url(), ['canal' => 'VIREMENT']);
        $paiement = Paiement::query()->latest('id')->firstOrFail();
        $service = app(ServicePaiement::class);
        $service->confirmer($paiement->reference, true);
        $service->confirmer($paiement->reference, true);
        $service->confirmer($paiement->reference, false);

        $this->assertSame(StatutPaiement::Confirme, $paiement->fresh()->statut);
        $this->assertSame(StatutCandidatureBoutique::Payee, $this->candidature->fresh()->statut);
        $this->assertCount(1, $this->sms->envoyes);
    }

    public function test_une_caution_non_payee_expire_apres_le_delai(): void
    {
        $this->travel(73)->hours();
        $this->artisan('raaga:expirer-reservations')->expectsOutputToContain('1 réservation(s) expirée(s).');

        $this->assertSame(StatutCandidatureBoutique::Expiree, $this->candidature->fresh()->statut);
        $this->actingAs($this->citoyen)->get($this->url())->assertOk()->assertSee('Délai de paiement dépassé');
        $this->actingAs($this->citoyen)->post($this->url(), ['canal' => 'VIREMENT'])->assertStatus(409);

        // La place est libérée : le citoyen peut candidater de nouveau.
        $this->actingAs($this->citoyen)->get('/boutiques/candidater')->assertOk();
    }

    public function test_acces_refuse_a_la_caution_d_un_autre_citoyen(): void
    {
        $autre = User::factory()->role(Role::Citoyen)->create(['statut_kyc' => StatutKyc::Valide]);

        $this->actingAs($autre)->get($this->url())->assertForbidden();
        $this->actingAs($autre)->post($this->url(), ['canal' => 'VIREMENT'])->assertForbidden();
        $this->actingAs($autre)->post($this->url('/simuler'), ['issue' => 'succes'])->assertForbidden();
        $this->actingAs($autre)->get($this->url('/recu'))->assertForbidden();
        $this->assertSame(0, Paiement::count());
    }
}
