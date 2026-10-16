<?php

namespace Tests\Feature\Compte;

use App\Contracts\SmsProvider;
use App\Enums\CompteDestination;
use App\Enums\Role;
use App\Enums\StatutBoutique;
use App\Enums\StatutKyc;
use App\Enums\StatutPaiement;
use App\Enums\StatutReservationBoutique;
use App\Http\Controllers\Admin\CautionsRembourserController;
use App\Models\AuditLog;
use App\Models\BoutiqueStatut;
use App\Models\DecisionAvance;
use App\Models\MoyenPaiement;
use App\Models\Paiement;
use App\Models\Relance;
use App\Models\Remboursement;
use App\Models\ReservationBoutique;
use App\Models\User;
use App\Notifications\AvanceBoutiqueCitoyen;
use App\Notifications\DecisionRendue;
use App\Notifications\RelancePaiement;
use App\Services\Boutiques\AvancesBoutiques;
use App\Services\Paiement\ServicePaiement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Tests\Support\ActeursParametrage;
use Tests\Support\Boutiques;
use Tests\Support\FakeSmsProvider;
use Tests\TestCase;

/**
 * Avance d'une boutique réservée : paiement par le circuit de la caution (opérateur, manuel, plafonds, compte de
 * location), attribution seulement après l'avance confirmée, relances, annulation automatique à la date limite (sauf
 * preuve en contrôle), puis retenue sur la caution proposée par le RÉGULATEUR et décidée par le VALIDATEUR.
 */
class AvanceBoutiquesTest extends TestCase
{
    use ActeursParametrage, RefreshDatabase;

    private FakeSmsProvider $sms;

    private User $controleur;

    private User $gestionnaire;

    protected function setUp(): void
    {
        parent::setUp();

        $this->sms = new FakeSmsProvider;
        $this->app->instance(SmsProvider::class, $this->sms);
        $this->creerActeurs();
        $this->controleur = User::factory()->role(Role::ControleurPaiements)->create();
        $this->gestionnaire = User::factory()->role(Role::GestionnaireSites)->create();
        config(['raaga.compte_location' => ['titulaire' => 'Raaga SA · location', 'banque' => 'Banque B', 'numero' => 'BF00 LOCATION']]);
        Boutiques::campagneOuverte();
    }

    /** Petite boutique (9 m²) réservée, caution payée (540 000 FCFA) : avance de 135 000 FCFA, à payer sous 30 jours. */
    private function reservee(int $numero = 1): ReservationBoutique
    {
        static $n = 0;
        $citoyen = User::factory()->role(Role::Citoyen)->create(['statut_kyc' => StatutKyc::Valide, 'telephone' => '+226700000'.sprintf('%02d', ++$n), 'email' => null]);
        Boutiques::reserver($this, $citoyen, Boutiques::boutique('A', $numero), 'B00'.sprintf('%05d', $n))->assertSessionHasNoErrors();
        $r = ReservationBoutique::query()->where('user_id', $citoyen->id)->sole();
        $this->actingAs($citoyen)->post(route('boutiques.reservation.payer', $r->reference), ['canal' => 'ORANGE_MONEY', 'telephone' => '70 12 34 56']);
        app(ServicePaiement::class)->confirmer($r->paiements()->latest('id')->value('reference'), true);

        return $r->refresh();
    }

    /** Réservation annulée automatiquement à la date limite : caution en attente de retenue. */
    private function annulee(int $numero = 1): Remboursement
    {
        $r = $this->reservee($numero);
        $r->forceFill(['avance_due_le' => now()->subMinute()])->save();
        app(AvancesBoutiques::class)->annulerEchues();

        return Remboursement::query()->where('reservation_boutique_id', $r->id)->sole();
    }

    private function proposer(Remboursement $rb, int $pct, string $motif = 'Retard de plus d’un mois sans nouvelles')
    {
        return $this->en($this->regulateur)->post(route('admin.cautions.proposer', $rb), ['retenue_pct' => $pct, 'motif' => $motif]);
    }

    private function decider(DecisionAvance $d, string $decision, ?string $motif = null)
    {
        return $this->en($this->validateur)->post(route('admin.a-valider.decider'), ['elements' => ['retenue:'.$d->id], 'decision' => $decision, 'mode' => 'ligne', 'motif' => $motif]);
    }

    public function test_paiement_de_l_avance_par_operateur(): void
    {
        $r = $this->reservee();
        $citoyen = $r->user;
        $this->assertSame([Paiement::AVANCE, 135_000], [$r->naturePaiementAttendue(), $r->montantAPayer()]);
        $this->assertTrue($r->avance_due_le->equalTo($r->reservee_le->copy()->addDays(30)));

        $this->actingAs($citoyen)->get(route('boutiques.suivi', $r->reference))->assertOk()->assertSee('data-payer-avance', false);
        $this->actingAs($citoyen)->post(route('boutiques.reservation.payer', $r->reference), ['canal' => 'ORANGE_MONEY', 'telephone' => '70 12 34 56'])->assertStatus(409);
        $this->actingAs($citoyen)->get(route('boutiques.avance.show', $r->reference))->assertOk()
            ->assertSee('Paiement de l’avance', false)->assertSee("135\u{00A0}000\u{00A0}FCFA")->assertSee('la réservation est annulée automatiquement', false);
        $this->actingAs($citoyen)->post(route('boutiques.avance.payer', $r->reference), ['canal' => 'ORANGE_MONEY', 'telephone' => '70 12 34 56'])
            ->assertRedirect(route('boutiques.avance.show', $r->reference));
        $avance = Paiement::query()->where('nature', Paiement::AVANCE)->sole();
        $this->assertSame([135_000, CompteDestination::Location], [$avance->montant, $avance->compte_destination]);

        // Opérateur : confirmation automatique.
        $this->actingAs($citoyen)->post(route('boutiques.avance.simuler', $r->reference), ['issue' => 'succes']);
        $r->refresh();
        $this->assertSame([$avance->reference, StatutReservationBoutique::Reservee], [$r->reference_avance, $r->statut]);
        $this->assertNotNull($r->avance_payee_le);
        $this->assertStringContainsString('avance reçue', end($this->sms->envoyes)['message']);
        $this->actingAs($citoyen)->get(route('boutiques.suivi', $r->reference))->assertOk()->assertSee('data-avance-payee', false);
        $this->assertStringStartsWith('%PDF', $this->actingAs($citoyen)->get(route('boutiques.avance.recu', $r->reference))->assertOk()->getContent());

        // Avance payée : plus d'annulation automatique, même après la date limite.
        $this->travel(40)->days();
        $this->artisan('raaga:relancer-paiements')->assertSuccessful();
        $this->assertSame(StatutReservationBoutique::Reservee, $r->fresh()->statut);
    }

    public function test_paiement_manuel_de_l_avance_et_complement(): void
    {
        $r = $this->reservee();
        $citoyen = $r->user;
        $this->actingAs($citoyen)->post(route('boutiques.avance.payer', $r->reference), ['canal' => 'VIREMENT'])->assertSessionHasNoErrors();
        $paiement = Paiement::query()->where('nature', Paiement::AVANCE)->sole();
        $this->actingAs($citoyen)->get(route('boutiques.avance.show', $r->reference))->assertOk()->assertSee('BF00 LOCATION')->assertSee($paiement->reference_versement);
        $preuve = fn () => $this->actingAs($citoyen)->post(route('boutiques.avance.preuve', $r->reference), ['preuve' => UploadedFile::fake()->create('avis.pdf', 100, 'application/pdf')]);
        $preuve()->assertSessionHasNoErrors();

        // Incomplet : délai de complément (48 heures) sur la date limite de l'avance.
        $this->en($this->controleur)->post(route('admin.paiements.controle', $paiement), ['controle' => Paiement::INCOMPLET, 'motif' => 'Il manque 35 000 FCFA',
            'controle_montant_recu' => 100_000])->assertSessionHas('succes');
        $this->assertEqualsWithDelta(48 * 3_600, now()->diffInSeconds($r->fresh()->avance_due_le), 5);
        $preuve()->assertSessionHasNoErrors();
        $this->en($this->controleur)->post(route('admin.paiements.controle', $paiement), ['controle' => Paiement::RECU])->assertSessionHas('succes');
        $this->assertNotNull($r->fresh()->avance_payee_le);
    }

    public function test_plafonds_et_attribution_seulement_apres_l_avance(): void
    {
        $r = $this->reservee();
        MoyenPaiement::parCode('ORANGE_MONEY')->forceFill(['usages' => [
            'ACTIONS' => ['autorise' => true, 'min' => null, 'max' => null], 'CAUTION' => ['autorise' => true, 'min' => null, 'max' => 100_000]]])->save();
        $this->assertSame(['VIREMENT'], array_map(fn ($m) => $m->code, $r->fresh()->canauxPaiement()));
        $this->actingAs($r->user)->post(route('boutiques.avance.payer', $r->reference), ['canal' => 'ORANGE_MONEY', 'telephone' => '70 12 34 56'])->assertSessionHasErrors('canal');

        $attribuer = fn () => $this->en($this->validateur)->post(route('admin.boutiques.attribuer', [$r->campagne_boutiques_id, $r]), ['contrat_signe_le' => now()->toDateString()]);
        $attribuer()->assertSessionHasErrors(['contrat_signe_le' => 'Attribution impossible : l’avance n’est pas encore confirmée.']);
        $this->actingAs($r->user)->post(route('boutiques.avance.payer', $r->reference), ['canal' => 'VIREMENT']);
        $this->actingAs($r->user)->post(route('boutiques.avance.preuve', $r->reference), ['preuve' => UploadedFile::fake()->create('avis.pdf', 100, 'application/pdf')]);
        $attribuer()->assertSessionHasErrors('contrat_signe_le');
        $paiement = Paiement::query()->where('nature', Paiement::AVANCE)->sole();
        $this->en($this->controleur)->post(route('admin.paiements.controle', $paiement), ['controle' => Paiement::RECU]);
        $attribuer()->assertSessionHas('succes');
        $this->assertSame([StatutReservationBoutique::Attribuee, $paiement->reference], [$r->fresh()->statut, $r->fresh()->reference_avance]);
    }

    public function test_relances_et_annulation_automatique_a_l_echeance(): void
    {
        Notification::fake();
        $r = $this->reservee();
        $boutique = $r->boutique;

        // 7 jours puis 24 heures avant la date limite ; la relance des 24 heures annonce l'annulation automatique.
        $this->travelTo($r->avance_due_le->copy()->subDays(7)->addHour());
        $this->artisan('raaga:relancer-paiements')->assertSuccessful();
        $this->travelTo($r->avance_due_le->copy()->subHours(23));
        $this->artisan('raaga:relancer-paiements')->assertSuccessful();
        $this->assertSame([Relance::AVANCE_7J, Relance::AVANCE_24H], $r->relances()->orderBy('id')->pluck('type')->all());
        Notification::assertSentTo($r->user, RelancePaiement::class, fn ($n) => str_contains($n->toArray($r->user)['message'], 'la réservation sera annulée automatiquement'));
        $this->assertSame(StatutReservationBoutique::Reservee, $r->fresh()->statut);

        // Échéance : annulation automatique, boutique libérée, historique, journal, citoyen notifié, caution en attente de retenue.
        $this->travelTo($r->avance_due_le->copy()->addMinute());
        $this->artisan('raaga:relancer-paiements')->expectsOutputToContain('1 réservation(s) annulée(s)')->assertSuccessful();
        $r->refresh();
        $this->assertSame([StatutReservationBoutique::Annulee, ReservationBoutique::ANNULATION_AVANCE, StatutBoutique::Disponible],
            [$r->statut, $r->annulation_motif, $boutique->fresh()->statut]);
        $this->assertSame('avance_non_payee', BoutiqueStatut::query()->where('boutique_id', $boutique->id)->latest('id')->value('motif'));
        $this->assertTrue(AuditLog::query()->where('action', 'reservation_boutique.annulee_avance_non_payee')->where('entite_id', (string) $r->id)->exists());
        Notification::assertSentTo($r->user, AvanceBoutiqueCitoyen::class, fn ($n) => str_contains($n->message(), 'l’avance n’a pas été payée dans le délai'));
        $rb = Remboursement::query()->where('reservation_boutique_id', $r->id)->sole();
        $this->assertSame([540_000, Remboursement::EN_ATTENTE_RETENUE, Remboursement::ANNULATION_AVANCE], [$rb->montant, $rb->statut, $rb->origine]);
        $this->actingAs($r->user)->get(route('boutiques.suivi', $r->reference))->assertOk()->assertSee('Annulée : avance non payée dans le délai')->assertDontSee('data-payer-avance', false);

        // Une seule annulation ; la boutique est de nouveau réservable.
        $this->artisan('raaga:relancer-paiements')->expectsOutputToContain('0 réservation(s) annulée(s)');
        $this->assertTrue($boutique->fresh()->statut->reservable());
    }

    public function test_paiement_refuse_apres_annulation(): void
    {
        $r = $this->reservee();
        // Demande en cours par opérateur, puis échéance dépassée : la consultation annule la réservation.
        $this->actingAs($r->user)->post(route('boutiques.avance.payer', $r->reference), ['canal' => 'ORANGE_MONEY', 'telephone' => '70 12 34 56']);
        $this->travelTo($r->avance_due_le->copy()->addMinute());
        $this->actingAs($r->user)->get(route('boutiques.avance.show', $r->reference))->assertRedirect(route('boutiques.suivi', $r->reference));
        $this->assertSame(StatutReservationBoutique::Annulee, $r->fresh()->statut);

        $this->actingAs($r->user)->post(route('boutiques.avance.payer', $r->reference), ['canal' => 'ORANGE_MONEY', 'telephone' => '70 12 34 56'])->assertStatus(409);
        $this->actingAs($r->user)->post(route('boutiques.avance.preuve', $r->reference), ['preuve' => UploadedFile::fake()->create('avis.pdf', 100, 'application/pdf')])
            ->assertSessionHasErrors('preuve');
        $tardif = Paiement::query()->where('nature', Paiement::AVANCE)->latest('id')->first();
        app(ServicePaiement::class)->confirmer($tardif->reference, true);
        $this->assertSame(StatutPaiement::Annule, $tardif->fresh()->statut);
        $this->assertNull($r->fresh()->avance_payee_le);
    }

    public function test_pas_d_annulation_pendant_le_controle_puis_annulation_apres_rejet_tardif(): void
    {
        $r = $this->reservee();
        $this->actingAs($r->user)->post(route('boutiques.avance.payer', $r->reference), ['canal' => 'VIREMENT']);
        $this->actingAs($r->user)->post(route('boutiques.avance.preuve', $r->reference), ['preuve' => UploadedFile::fake()->create('avis.pdf', 100, 'application/pdf')]);
        $paiement = Paiement::query()->where('nature', Paiement::AVANCE)->sole();

        // Échéance dépassée pendant le contrôle : rien ne se passe.
        $this->travelTo($r->avance_due_le->copy()->addDays(3));
        $this->artisan('raaga:relancer-paiements')->assertSuccessful();
        $this->assertSame(StatutReservationBoutique::Reservee, $r->fresh()->statut);
        $this->actingAs($r->user)->get(route('boutiques.suivi', $r->reference))->assertOk()->assertSee('data-avance-controle', false);

        // Preuve rejetée après la date limite : annulation au passage suivant.
        $this->en($this->controleur)->post(route('admin.paiements.controle', $paiement), ['controle' => Paiement::REJETE, 'motif' => 'Bordereau illisible'])->assertSessionHas('succes');
        $this->assertSame(StatutReservationBoutique::Reservee, $r->fresh()->statut);
        $this->artisan('raaga:relancer-paiements')->assertSuccessful();
        $this->assertSame([StatutReservationBoutique::Annulee, StatutBoutique::Disponible], [$r->fresh()->statut, $r->boutique->fresh()->statut]);
    }

    public function test_retenue_proposee_puis_confirmee_a_0_10_et_100_pourcent(): void
    {
        Notification::fake();
        foreach ([[1, 0, 540_000, Remboursement::A_EFFECTUER], [2, 10, 486_000, Remboursement::A_EFFECTUER], [3, 100, 0, Remboursement::RETENUE_TOTALE]] as [$numero, $pct, $montant, $statut]) {
            $rb = $this->annulee($numero);
            $this->en($this->regulateur)->get(route('admin.cautions'))->assertOk()->assertSee($rb->reservation->reference)->assertSee('Proposer la retenue');
            $this->proposer($rb, $pct)->assertSessionHas('succes', 'Retenue proposée au VALIDATEUR.');
            $d = DecisionAvance::query()->where('remboursement_id', $rb->id)->sole();
            $this->en($this->validateur)->get(route('admin.a-valider.rubrique', ['rubrique' => 'cautions', 'detail' => 'retenue:'.$d->id]))->assertOk()
                ->assertSee('data-detail="retenue:'.$d->id.'"', false)->assertSee($pct.' %');
            $this->decider($d, 'confirmer')->assertSessionHas('succes');

            $rb->refresh();
            $this->assertSame([$montant, $statut, $pct, DecisionAvance::CONFIRMEE], [$rb->montant, $rb->statut, $rb->retenue_pct, $d->fresh()->statut]);
            Notification::assertSentTo($rb->user, AvanceBoutiqueCitoyen::class, fn ($n) => str_contains($n->message(), $pct === 100 ? 'aucun remboursement' : 'retenue de '.$pct.' %'));
        }

        // Le CONTRÔLEUR DES PAIEMENTS suit les remboursements à effectuer ; la retenue de 100 % reste enregistrée.
        $this->en($this->controleur)->get(route('admin.remboursements'))->assertOk()->assertSee("486\u{00A0}000\u{00A0}FCFA")->assertSee('Retenue de 100 % : rien à verser');
        // Plus aucune caution en attente de retenue.
        $this->assertSame(0, CautionsRembourserController::requete()->count());
    }

    public function test_rejet_et_pas_d_auto_validation_et_droits_par_profil(): void
    {
        Notification::fake();
        $rb = $this->annulee();

        // Motif obligatoire, retenue entre 0 et 100 %.
        $this->proposer($rb, 10, '')->assertSessionHasErrors('motif');
        $this->proposer($rb, 120)->assertSessionHasErrors('retenue_pct');
        $this->proposer($rb, 50)->assertSessionHas('succes');
        $this->proposer($rb, 20)->assertSessionHasErrors('retenue_pct');
        $d = DecisionAvance::sole();

        // Auteur de la proposition : jamais décideur ; profils sans le droit : refus.
        try {
            app(AvancesBoutiques::class)->confirmer($d, $this->regulateur);
            $this->fail('Auto-validation acceptée.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('un autre VALIDATEUR', collect($e->errors())->flatten()->first());
        }
        $this->en($this->regulateur)->post(route('admin.cautions.confirmer', $d))->assertForbidden();
        $this->en($this->validateur)->post(route('admin.cautions.proposer', $rb), ['retenue_pct' => 0, 'motif' => 'Je propose aussi'])->assertForbidden();

        // Rejet : rien ne change, le RÉGULATEUR est notifié et peut proposer de nouveau.
        $this->decider($d, 'rejeter', 'Retenue trop élevée')->assertSessionHas('succes');
        $this->assertSame([DecisionAvance::REJETEE, Remboursement::EN_ATTENTE_RETENUE, 540_000], [$d->fresh()->statut, $rb->fresh()->statut, $rb->fresh()->montant]);
        Notification::assertSentTo($this->regulateur, DecisionRendue::class);
        $this->proposer($rb, 10)->assertSessionHas('succes');

        // GESTIONNAIRE : statuts sans montant ni nom, aucune action ; VALIDATEUR : consulte ; autres profils : refus.
        $page = $this->en($this->gestionnaire)->get(route('admin.cautions'))->assertOk()->assertSee($rb->reservation->reference)->assertSee('En attente de retenue');
        $page->assertDontSee('FCFA')->assertDontSee($rb->user->name)->assertDontSee('Proposer la retenue');
        $this->en($this->gestionnaire)->post(route('admin.cautions.proposer', $rb), ['retenue_pct' => 0, 'motif' => 'Essai du gestionnaire'])->assertForbidden();
        $this->en($this->validateur)->get(route('admin.cautions'))->assertOk()->assertSee("540\u{00A0}000\u{00A0}FCFA")->assertDontSee('Proposer la retenue');
        foreach ([$this->controleur, $this->superAdmin, User::factory()->role(Role::Admin)->create()] as $profil) {
            $this->en($profil)->get(route('admin.cautions'))->assertForbidden();
        }
        // Tant que la retenue n'est pas décidée, le CONTRÔLEUR ne peut pas déclarer le remboursement.
        $this->en($this->controleur)->post(route('admin.remboursements.execution', $rb), ['mode' => 'Virement', 'reference_operation' => 'VIR-1'])->assertSessionHasErrors();
    }
}
