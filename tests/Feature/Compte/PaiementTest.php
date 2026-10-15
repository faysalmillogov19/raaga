<?php

namespace Tests\Feature\Compte;

use App\Contracts\PaymentProvider;
use App\Enums\CanalSouscription;
use App\Enums\MethodeAllocation;
use App\Enums\ModeSaisie;
use App\Enums\PayeurFrais;
use App\Enums\PublicAutorise;
use App\Enums\RegleArret;
use App\Enums\RegleSiEchec;
use App\Enums\Role;
use App\Enums\StatutKyc;
use App\Enums\StatutPaiement;
use App\Enums\StatutSouscription;
use App\Enums\StatutTranche;
use App\Enums\TypeFraisSouscription;
use App\Models\AuditLog;
use App\Models\Paiement;
use App\Models\Souscription;
use App\Models\Tranche;
use App\Models\User;
use App\Services\Paiement\ServicePaiement;
use App\Services\Paiement\SimulatedProvider;
use App\Support\VerrouEnvironnement;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class PaiementTest extends TestCase
{
    use RefreshDatabase;

    private User $citoyen;

    private Souscription $souscription;

    protected function setUp(): void
    {
        parent::setUp();

        $tranche = Tranche::create([
            'statut' => StatutTranche::Ouverte, 'nom' => 'Tranche 1 – 2026', 'valeur_unitaire' => 10_000,
            'min_actions_par_souscription' => 1, 'objectif_montant' => 3_000_000_000,
            'date_ouverture' => now()->subDay(), 'date_cloture' => now()->addDays(30),
            'publics_autorises' => [PublicAutorise::ResidentBf->value], 'mode_saisie' => ModeSaisie::NbActions,
            'souscriptions_multiples' => true, 'regle_arret' => RegleArret::ArretAObjectif, 'methode_allocation' => MethodeAllocation::Prorata,
            'regle_si_echec' => RegleSiEchec::RemboursementIntegral, 'delai_remboursement_jours' => 30,
            'frais_paiement_supportes_par' => PayeurFrais::Raaga, 'frais_souscription_type' => TypeFraisSouscription::Aucun,
            'canaux_actifs' => ['ORANGE_MONEY', 'VIREMENT', 'ESPECES'],
            'delai_paiement_heures' => 48, 'version' => 1, 'cree_par' => User::factory()->create()->id,
        ]);

        $this->citoyen = User::factory()->role(Role::Citoyen)->create(['statut_kyc' => StatutKyc::Valide, 'pays_residence' => 'BF', 'telephone' => '+22670123456']);
        $this->souscription = $this->reserver($this->citoyen, $tranche);
    }

    private function reserver(User $user, Tranche $tranche): Souscription
    {
        return Souscription::create([
            'reference' => 'RAA-B-'.strtoupper(fake()->bothify('????####')), 'user_id' => $user->id, 'tranche_id' => $tranche->id,
            'nb_actions_demandees' => 25, 'valeur_unitaire' => 10_000, 'montant' => 250_000, 'frais_souscription' => 0,
            'canal' => CanalSouscription::Web, 'statut' => StatutSouscription::Reservee, 'expire_le' => now()->addHours(48),
        ]);
    }

    private function url(string $suffixe = ''): string
    {
        return '/paiement/'.$this->souscription->reference.$suffixe;
    }

    private function payerOrangeMoney(): Paiement
    {
        $this->actingAs($this->citoyen)->post($this->url(), ['canal' => 'ORANGE_MONEY', 'telephone' => '70 12 34 56'])
            ->assertRedirect($this->url())->assertSessionHasNoErrors();

        return Paiement::query()->latest('id')->firstOrFail();
    }

    public function test_succes_la_souscription_passe_de_reservee_a_payee(): void
    {
        $this->actingAs($this->citoyen)->get('/paiement')->assertRedirect($this->url());
        $this->actingAs($this->citoyen)->get($this->url())->assertOk()
            ->assertSee('Paiement simulé, environnement de recette')
            ->assertSee("250\u{00A0}000\u{00A0}FCFA")
            ->assertSee('Orange Money')->assertSee('Virement bancaire')->assertSee('Espèces au guichet')
            ->assertDontSee('Moov Money');

        $paiement = $this->payerOrangeMoney();
        $this->assertSame(StatutPaiement::EnAttente, $paiement->statut);
        $this->assertSame('+22670123456', $paiement->metadonnees['telephone']);

        // Reprise : la page rechargée retrouve la demande en cours.
        $this->actingAs($this->citoyen)->get($this->url())->assertOk()->assertSee('En attente de confirmation')->assertSee('Simuler un paiement réussi');
        $this->actingAs($this->citoyen)->getJson($this->url('/statut'))->assertExactJson(['objet' => 'RESERVEE', 'paiement' => 'EN_ATTENTE']);

        $this->actingAs($this->citoyen)->post($this->url('/simuler'), ['issue' => 'succes'])->assertRedirect($this->url());

        $this->assertSame(StatutSouscription::Payee, $this->souscription->fresh()->statut);
        $this->assertNotNull($this->souscription->fresh()->payee_le);
        $this->assertSame(StatutPaiement::Confirme, $paiement->fresh()->statut);

        $this->actingAs($this->citoyen)->get($this->url())->assertRedirect($this->url('/confirmation'));
        $this->actingAs($this->citoyen)->get($this->url('/confirmation'))->assertOk()
            ->assertSee('Votre souscription est enregistrée')->assertSee('En attente d’allocation')->assertSee($paiement->reference);
    }

    public function test_echec_puis_nouvel_essai(): void
    {
        $premier = $this->payerOrangeMoney();
        $this->actingAs($this->citoyen)->post($this->url('/simuler'), ['issue' => 'echec']);

        $this->assertSame(StatutPaiement::Echoue, $premier->fresh()->statut);
        $this->assertSame(StatutSouscription::Reservee, $this->souscription->fresh()->statut);
        $this->actingAs($this->citoyen)->get($this->url())->assertOk()->assertSee('Le paiement a échoué')->assertSee('Réessayer le paiement');

        $second = $this->payerOrangeMoney();
        $this->assertNotSame($premier->reference, $second->reference);
        $this->actingAs($this->citoyen)->post($this->url('/simuler'), ['issue' => 'succes']);
        $this->assertSame(StatutSouscription::Payee, $this->souscription->fresh()->statut);
    }

    public function test_une_reference_de_paiement_n_est_jamais_confirmee_deux_fois(): void
    {
        $paiement = $this->payerOrangeMoney();
        $service = app(ServicePaiement::class);

        $service->confirmer($paiement->reference, true);
        $date = $paiement->fresh()->date_confirmation;
        $this->travel(5)->minutes();

        // Rejeu du même retour, puis retour contradictoire : aucun effet.
        $service->confirmer($paiement->reference, true);
        $service->confirmer($paiement->reference, false);

        $this->assertSame(StatutPaiement::Confirme, $paiement->fresh()->statut);
        $this->assertEquals($date, $paiement->fresh()->date_confirmation);
        $this->assertSame(StatutSouscription::Payee, $this->souscription->fresh()->statut);
        $this->assertSame(1, AuditLog::query()->where('action', 'paiement.confirme')->count());
        $this->assertSame(0, AuditLog::query()->where('action', 'paiement.echoue')->count());
    }

    public function test_le_simulateur_refuse_de_fonctionner_en_production(): void
    {
        $this->app['env'] = 'production';

        try {
            new SimulatedProvider;
            $this->fail('Le simulateur aurait dû refuser de fonctionner.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('paiement simulé', $e->getMessage());
        }

        // Production sans opérateur réel (même « simule » demandé) : « moyen de paiement indisponible », aucun paiement démarré.
        VerrouEnvironnement::verifier('production', false);
        config(['raaga.paiement.fournisseur' => 'simule']);
        $this->actingAs($this->citoyen)->get($this->url())->assertOk()
            ->assertSee('Moyen de paiement indisponible')->assertDontSee('Paiement simulé');
        // En « production », le jeton CSRF n'est plus ignoré par les tests : seul ce contrôle est levé ici.
        $this->withoutMiddleware(PreventRequestForgery::class)->actingAs($this->citoyen)->post($this->url(), ['canal' => 'ORANGE_MONEY', 'telephone' => '70123456'])
            ->assertSessionHasErrors(['canal' => 'Moyen de paiement indisponible']);
        $this->assertSame(0, Paiement::count());
        $this->assertThrows(fn () => app(PaymentProvider::class), RuntimeException::class);
    }

    public function test_une_reservation_non_payee_expire_apres_le_delai(): void
    {
        $paiement = $this->payerOrangeMoney();
        $this->travel(47)->hours();
        $this->artisan('raaga:expirer-reservations')->assertSuccessful();
        $this->assertSame(StatutSouscription::Reservee, $this->souscription->fresh()->statut);

        $this->travel(2)->hours();
        $this->artisan('raaga:expirer-reservations')->expectsOutputToContain('1 réservation(s) expirée(s).')->assertSuccessful();

        $this->assertSame(StatutSouscription::Expiree, $this->souscription->fresh()->statut);
        $this->assertSame(StatutPaiement::Annule, $paiement->fresh()->statut);

        // Un retour tardif de l'opérateur ne paie pas une réservation expirée.
        app(ServicePaiement::class)->confirmer($paiement->reference, true);
        $this->assertSame(StatutSouscription::Expiree, $this->souscription->fresh()->statut);

        $this->actingAs($this->citoyen)->get($this->url())->assertOk()->assertSee('Délai de paiement dépassé');
        $this->actingAs($this->citoyen)->post($this->url(), ['canal' => 'VIREMENT'])->assertStatus(409);
    }

    public function test_acces_refuse_a_la_reservation_d_un_autre_citoyen(): void
    {
        $autre = User::factory()->role(Role::Citoyen)->create(['statut_kyc' => StatutKyc::Valide]);

        $this->actingAs($autre)->get($this->url())->assertForbidden();
        $this->actingAs($autre)->post($this->url(), ['canal' => 'VIREMENT'])->assertForbidden();
        $this->actingAs($autre)->post($this->url('/simuler'), ['issue' => 'succes'])->assertForbidden();
        $this->actingAs($autre)->getJson($this->url('/statut'))->assertForbidden();
        $this->actingAs($autre)->get($this->url('/confirmation'))->assertForbidden();
        $this->actingAs($autre)->get('/paiement')->assertOk()->assertSee('Le paiement en ligne n’est pas encore ouvert');

        $this->assertSame(0, Paiement::count());
        $this->assertSame(StatutSouscription::Reservee, $this->souscription->fresh()->statut);
    }

    public function test_instructions_du_virement_et_des_especes(): void
    {
        config(['raaga.compte_sequestre' => ['titulaire' => 'Raaga SA · séquestre', 'banque' => 'Banque de test', 'numero' => 'BF00 0000 1111']]);

        $this->actingAs($this->citoyen)->get($this->url())->assertOk()
            ->assertSee('Raaga SA · séquestre')->assertSee('BF00 0000 1111')
            ->assertSee('La référence de virement est obligatoire')
            ->assertSee('Présentez ce code de réservation');

        $this->actingAs($this->citoyen)->post($this->url(), ['canal' => 'ORANGE_MONEY'])->assertSessionHasErrors('telephone');
        $this->actingAs($this->citoyen)->post($this->url(), ['canal' => 'MOOV_MONEY', 'telephone' => '70123456'])->assertSessionHasErrors('canal');
    }
}
