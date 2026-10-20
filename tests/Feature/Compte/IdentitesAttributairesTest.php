<?php

namespace Tests\Feature\Compte;

use App\Contracts\SmsProvider;
use App\Enums\Role;
use App\Enums\StatutKyc;
use App\Enums\StatutReservationBoutique;
use App\Models\Boutique;
use App\Models\Remboursement;
use App\Models\ReservationBoutique;
use App\Models\User;
use App\Notifications\IdentiteAttributaireCitoyen;
use App\Services\Paiement\ServicePaiement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\Support\ActeursParametrage;
use Tests\Support\Boutiques;
use Tests\Support\FakeSmsProvider;
use Tests\TestCase;

/**
 * Identité des attributaires : rejetée ou à mettre à jour, l'attributaire est prévenu et dispose du délai de correction
 * de la campagne ; corrigée à temps, le délai est levé ; sinon la réservation est annulée et la boutique passe au
 * suivant de sa liste d'attente. L'attribution définitive exige une identité validée.
 */
class IdentitesAttributairesTest extends TestCase
{
    use ActeursParametrage, RefreshDatabase;

    private FakeSmsProvider $sms;

    private int $n = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sms = new FakeSmsProvider;
        $this->app->instance(SmsProvider::class, $this->sms);
        $this->creerActeurs();
        config(['raaga.compte_location' => ['titulaire' => 'Raaga SA · location', 'banque' => 'Banque B', 'numero' => 'BF00 LOCATION']]);
        // Délai commun de correction (« Délais de paiement ») : 48 heures par défaut.
        Boutiques::campagneOuverte();
    }

    private function citoyen(): User
    {
        $this->n++;

        return User::factory()->role(Role::Citoyen)->create(['statut_kyc' => StatutKyc::Valide, 'telephone' => '+226700000'.sprintf('%02d', $this->n), 'email' => null]);
    }

    private function retenu(User $u, Boutique $b): ReservationBoutique
    {
        Boutiques::reserver($this, $u, $b, 'P'.sprintf('%07d', $u->id))->assertSessionHasNoErrors();

        return ReservationBoutique::query()->where('user_id', $u->id)->where('boutique_id', $b->id)->latest('id')->firstOrFail();
    }

    private function payerCaution(ReservationBoutique $r): void
    {
        $this->actingAs($r->user)->post(route('boutiques.reservation.payer', $r->reference), ['canal' => 'ORANGE_MONEY', 'telephone' => '70 12 34 56']);
        app(ServicePaiement::class)->confirmer($r->paiements()->latest('id')->value('reference'), true);
    }

    public function test_identite_rejetee_delai_fixe_puis_leve_apres_correction(): void
    {
        Notification::fake();
        $awa = $this->citoyen();
        $r = $this->retenu($awa, Boutiques::boutique('A'));
        $awa->forceFill(['statut_kyc' => StatutKyc::Rejete])->save();

        $this->artisan('raaga:expirer-reservations')->expectsOutputToContain('1 signalée(s)');
        $r->refresh();
        $this->assertNotNull($r->kyc_a_corriger_avant);
        $this->assertLessThan(5, abs($r->kyc_a_corriger_avant->diffInSeconds(now()->addDays(2))));
        Notification::assertSentTo($awa, IdentiteAttributaireCitoyen::class);
        $this->assertTrue(collect($this->sms->envoyes)->contains(fn ($s) => str_contains($s['message'], 'identité doit être corrigée')));
        $this->actingAs($awa)->get(route('boutiques.suivi', $r->reference))->assertOk()->assertSee('data-identite-a-corriger', false);

        // Signalée une seule fois.
        $this->artisan('raaga:expirer-reservations')->expectsOutputToContain('0 signalée(s)');

        // Pièces renvoyées (en attente de vérification) : délai levé, réservation gardée même après la date.
        $awa->forceFill(['statut_kyc' => StatutKyc::EnAttente])->save();
        $this->artisan('raaga:expirer-reservations')->expectsOutputToContain('1 corrigée(s)');
        $this->travel(3)->days();
        $this->artisan('raaga:expirer-reservations')->assertSuccessful();
        $this->assertNull($r->fresh()->kyc_a_corriger_avant);
        $this->assertNotSame(StatutReservationBoutique::Annulee, $r->fresh()->statut);
    }

    public function test_non_corrigee_dans_le_delai_annulee_et_boutique_au_suivant(): void
    {
        [$awa, $issa] = [$this->citoyen(), $this->citoyen()];
        $a1 = Boutiques::boutique('A');
        $r = $this->retenu($awa, $a1);
        Boutiques::reserver($this, $issa, $a1, 'P'.sprintf('%07d', $issa->id))->assertSessionHasNoErrors();
        $this->payerCaution($r);
        $awa->forceFill(['statut_kyc' => StatutKyc::AMettreAJour])->save();
        $this->artisan('raaga:expirer-reservations')->assertSuccessful();

        $this->travel(2)->days();
        $this->travel(1)->minute();
        $this->artisan('raaga:expirer-reservations')->expectsOutputToContain('1 réservation(s) annulée(s)');
        $r->refresh();
        $this->assertSame([StatutReservationBoutique::Annulee, ReservationBoutique::ANNULATION_KYC], [$r->statut, $r->annulation_motif]);
        $rb = Remboursement::query()->where('reservation_boutique_id', $r->id)->sole();
        $this->assertSame([Remboursement::KYC_NON_CORRIGE, Remboursement::EN_ATTENTE_RETENUE], [$rb->origine, $rb->statut]);
        // Boutique au suivant de la liste.
        $this->assertSame($issa->id, ReservationBoutique::query()->where('boutique_id', $a1->id)->where('statut', StatutReservationBoutique::PreReservee)->sole()->user_id);
        $this->actingAs($awa)->get(route('boutiques.suivi', $r->reference))->assertOk()->assertSee('votre identité n’a pas été corrigée dans le délai', false);
    }

    public function test_attribution_seulement_avec_une_identite_validee(): void
    {
        $awa = $this->citoyen();
        $r = $this->retenu($awa, Boutiques::boutique('A'));
        $this->payerCaution($r);
        $r->refresh()->forceFill(['avance_payee_le' => now()])->save();
        $awa->forceFill(['statut_kyc' => StatutKyc::EnAttente])->save();
        $attribuer = fn () => $this->en($this->validateur)->post(route('admin.boutiques.attribuer', [$r->campagne_boutiques_id, $r]), ['contrat_signe_le' => now()->toDateString()]);

        $attribuer()->assertSessionHasErrors(['contrat_signe_le' => __('admin.boutiques.erreurs.identite_non_validee')]);
        $this->assertSame(StatutReservationBoutique::Reservee, $r->fresh()->statut);

        $awa->forceFill(['statut_kyc' => StatutKyc::Valide])->save();
        $attribuer()->assertSessionHasNoErrors();
        $this->assertSame(StatutReservationBoutique::Attribuee, $r->fresh()->statut);
    }
}
