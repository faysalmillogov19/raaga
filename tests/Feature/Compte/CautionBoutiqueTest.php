<?php

namespace Tests\Feature\Compte;

use App\Contracts\SmsProvider;
use App\Enums\Role;
use App\Enums\StatutBoutique;
use App\Enums\StatutKyc;
use App\Enums\StatutPaiement;
use App\Enums\StatutReservationBoutique;
use App\Models\Paiement;
use App\Models\ReservationBoutique;
use App\Models\User;
use App\Services\Paiement\ServicePaiement;
use App\Support\Recu;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\Boutiques;
use Tests\Support\FakeSmsProvider;
use Tests\TestCase;

class CautionBoutiqueTest extends TestCase
{
    use RefreshDatabase;

    private FakeSmsProvider $sms;

    private User $citoyen;

    private ReservationBoutique $candidature;

    protected function setUp(): void
    {
        parent::setUp();

        $this->sms = new FakeSmsProvider;
        $this->app->instance(SmsProvider::class, $this->sms);

        $campagne = Boutiques::campagneOuverte();
        $this->citoyen = User::factory()->role(Role::Citoyen)->create(['statut_kyc' => StatutKyc::Valide, 'telephone' => '+22670123456']);
        Boutiques::reserver($this, $this->citoyen, Boutiques::boutique('B'))->assertSessionHasNoErrors()->assertRedirect();
        $this->candidature = ReservationBoutique::sole();
    }

    private function url(string $suffixe = ''): string
    {
        return '/boutiques/reservations/'.$this->candidature->reference.'/caution'.$suffixe;
    }

    public function test_la_caution_se_paie_avec_le_module_de_paiement_et_donne_un_recu(): void
    {
        $this->actingAs($this->citoyen)->get($this->url())->assertOk()
            ->assertSee('Paiement de la caution')
            ->assertSee('Paiement simulé, environnement de recette')
            ->assertSee('Grande boutique')->assertSee('MCE-RC-B-001')->assertSee("1\u{00A0}440\u{00A0}000\u{00A0}FCFA")->assertSee('data-compte-rebours', false)
            ->assertSee('Orange Money')->assertSee('Virement bancaire')->assertDontSee('Moov Money');

        $this->actingAs($this->citoyen)->post($this->url(), ['canal' => 'ORANGE_MONEY', 'telephone' => '70 12 34 56'])->assertRedirect($this->url());
        $paiement = Paiement::sole();
        $this->assertSame($this->candidature->id, $paiement->reservation_boutique_id);
        $this->assertNull($paiement->souscription_id);
        // Seule la caution confirme la réservation (12 mois de loyer) ; l'avance est due avant le contrat.
        $this->assertSame([1_440_000, 0], [$paiement->montant, $paiement->frais]);

        $this->actingAs($this->citoyen)->getJson($this->url('/statut'))->assertExactJson(['objet' => 'PRE_RESERVEE', 'paiement' => 'EN_ATTENTE']);
        $this->actingAs($this->citoyen)->post($this->url('/simuler'), ['issue' => 'succes'])->assertRedirect($this->url());

        $this->assertSame(StatutReservationBoutique::Reservee, $this->candidature->fresh()->statut);
        $this->assertNotNull($this->candidature->fresh()->payee_le);
        $this->assertSame(StatutBoutique::Reservee, $this->candidature->boutique->fresh()->statut);
        $this->assertSame(StatutPaiement::Confirme, $paiement->fresh()->statut);

        // SMS avec le numéro de reçu.
        $this->assertCount(1, $this->sms->envoyes);
        $this->assertStringContainsString(Recu::numero($paiement->fresh()), $this->sms->envoyes[0]['message']);
        $this->assertStringContainsString('caution reçue', $this->sms->envoyes[0]['message']);

        // Payée : l'écran de paiement renvoie au suivi, qui propose le reçu.
        $this->actingAs($this->citoyen)->get($this->url())->assertRedirect(route('boutiques.suivi', $this->candidature->reference));
        $this->actingAs($this->citoyen)->get(route('boutiques.suivi', $this->candidature->reference))->assertOk()
            ->assertSee('la boutique est réservée à votre nom')->assertSee('Télécharger le reçu de caution (PDF)');

        $html = view('documents.recu-caution', ['candidature' => $this->candidature->fresh()->load(['campagne', 'boutique.type', 'user']),
            'paiement' => $paiement->fresh(), 'numero' => Recu::numero($paiement->fresh()), 'pdf' => true])->render();
        foreach (['Reçu de caution', $this->candidature->reference, 'MCE-RC-B-001', 'Grande boutique', "1\u{00A0}440\u{00A0}000\u{00A0}FCFA", 'L’achat d’actions ne donne aucun droit ni priorité pour obtenir une boutique.'] as $attendu) {
            $this->assertStringContainsString($attendu, $html);
        }
        $this->assertStringStartsWith('%PDF', $this->actingAs($this->citoyen)->get($this->url('/recu'))->assertOk()->assertHeader('Content-Type', 'application/pdf')->getContent());
    }

    public function test_confirmation_idempotente_et_echec(): void
    {
        $this->actingAs($this->citoyen)->post($this->url(), ['canal' => 'ORANGE_MONEY', 'telephone' => '70 12 34 56']);
        $this->actingAs($this->citoyen)->post($this->url('/simuler'), ['issue' => 'echec']);
        $this->assertSame(StatutReservationBoutique::PreReservee, $this->candidature->fresh()->statut);
        $this->actingAs($this->citoyen)->get($this->url())->assertOk()->assertSee('Le paiement a échoué');

        $this->actingAs($this->citoyen)->post($this->url(), ['canal' => 'ORANGE_MONEY', 'telephone' => '70 12 34 56']);
        $paiement = Paiement::query()->latest('id')->firstOrFail();
        $service = app(ServicePaiement::class);
        $service->confirmer($paiement->reference, true);
        $service->confirmer($paiement->reference, true);
        $service->confirmer($paiement->reference, false);

        $this->assertSame(StatutPaiement::Confirme, $paiement->fresh()->statut);
        $this->assertSame(StatutReservationBoutique::Reservee, $this->candidature->fresh()->statut);
        $this->assertCount(1, $this->sms->envoyes);
    }

    public function test_une_caution_non_payee_expire_apres_le_delai(): void
    {
        $this->travel(73)->hours();
        $this->artisan('raaga:expirer-reservations')->expectsOutputToContain('1 réservation(s) expirée(s).');

        $this->assertSame(StatutReservationBoutique::Expiree, $this->candidature->fresh()->statut);
        $this->actingAs($this->citoyen)->get($this->url())->assertOk()->assertSee('Délai de paiement dépassé');
        $this->actingAs($this->citoyen)->post($this->url(), ['canal' => 'VIREMENT'])->assertStatus(409);

        // La boutique est redevenue disponible : le citoyen peut la réserver de nouveau.
        $this->assertSame(StatutBoutique::Disponible, $this->candidature->boutique->fresh()->statut);
        Boutiques::reserver($this, $this->citoyen, Boutiques::boutique('B'))->assertSessionHasNoErrors()->assertRedirect();
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
