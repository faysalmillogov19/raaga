<?php

namespace Tests\Feature\Compte;

use App\Contracts\SmsProvider;
use App\Enums\CompteDestination;
use App\Enums\Role;
use App\Enums\StatutBoutique;
use App\Enums\StatutKyc;
use App\Enums\StatutReservationBoutique;
use App\Models\DecisionAvance;
use App\Models\MoyenPaiement;
use App\Models\Paiement;
use App\Models\Relance;
use App\Models\Remboursement;
use App\Models\ReservationBoutique;
use App\Models\User;
use App\Notifications\AvanceBoutiqueCitoyen;
use App\Notifications\DecisionRendue;
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
 * location), attribution seulement après l'avance confirmée, relances, retard sans action automatique, décisions au
 * cas par cas (prolonger, annuler avec retenue, attendre) confirmées ou rejetées par le VALIDATEUR.
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

    /** Petite boutique (9 m²) réservée, caution payée : avance de 135 000 FCFA (3 mois de 45 000), à payer sous 30 jours. */
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

    /** Dossier passé « avance en retard » (date limite dépassée, tâche planifiée). */
    private function enRetard(int $numero = 1): ReservationBoutique
    {
        $r = $this->reservee($numero);
        $r->forceFill(['avance_due_le' => now()->subHour()])->save();
        app(AvancesBoutiques::class)->marquerRetards();

        return $r->refresh();
    }

    private function proposer(ReservationBoutique $r, array $champs)
    {
        return $this->en($this->regulateur)->post(route('admin.avances.proposer', $r), ['motif' => 'Commerçant injoignable depuis deux semaines', ...$champs]);
    }

    private function decider(DecisionAvance $d, string $decision, ?string $motif = null)
    {
        return $this->en($this->validateur)->post(route('admin.a-valider.decider'), ['elements' => ['avance:'.$d->id], 'decision' => $decision, 'mode' => 'ligne', 'motif' => $motif]);
    }

    public function test_paiement_de_l_avance_par_operateur(): void
    {
        $r = $this->reservee();
        $citoyen = $r->user;
        $this->assertSame([Paiement::AVANCE, 135_000], [$r->naturePaiementAttendue(), $r->montantAPayer()]);
        $this->assertTrue($r->avance_due_le->equalTo($r->reservee_le->copy()->addDays(30)));

        // Dossier : bouton « Payer l'avance » ; la caution ne se paie plus.
        $this->actingAs($citoyen)->get(route('boutiques.suivi', $r->reference))->assertOk()->assertSee('data-payer-avance', false);
        $this->actingAs($citoyen)->post(route('boutiques.reservation.payer', $r->reference), ['canal' => 'ORANGE_MONEY', 'telephone' => '70 12 34 56'])->assertStatus(409);

        $this->actingAs($citoyen)->get(route('boutiques.avance.show', $r->reference))->assertOk()
            ->assertSee('Paiement de l’avance', false)->assertSee("135\u{00A0}000\u{00A0}FCFA")->assertSee('Orange Money')->assertSee('Virement bancaire');
        $this->actingAs($citoyen)->post(route('boutiques.avance.payer', $r->reference), ['canal' => 'ORANGE_MONEY', 'telephone' => '70 12 34 56'])
            ->assertRedirect(route('boutiques.avance.show', $r->reference));
        $avance = Paiement::query()->where('nature', Paiement::AVANCE)->sole();
        $this->assertSame([135_000, CompteDestination::Location], [$avance->montant, $avance->compte_destination]);

        // Opérateur : confirmation automatique.
        $this->actingAs($citoyen)->post(route('boutiques.avance.simuler', $r->reference), ['issue' => 'succes']);
        $r->refresh();
        $this->assertNotNull($r->avance_payee_le);
        $this->assertSame($avance->reference, $r->reference_avance);
        $this->assertSame(StatutReservationBoutique::Reservee, $r->statut);
        $this->assertStringContainsString('avance reçue', end($this->sms->envoyes)['message']);
        $this->actingAs($citoyen)->get(route('boutiques.avance.show', $r->reference))->assertRedirect(route('boutiques.suivi', $r->reference));
        $this->actingAs($citoyen)->get(route('boutiques.suivi', $r->reference))->assertOk()->assertSee('data-avance-payee', false)->assertSee('Télécharger le reçu de l’avance (PDF)', false);
        $this->assertStringStartsWith('%PDF', $this->actingAs($citoyen)->get(route('boutiques.avance.recu', $r->reference))->assertOk()->getContent());
        $this->actingAs($citoyen)->post(route('boutiques.avance.payer', $r->reference), ['canal' => 'ORANGE_MONEY', 'telephone' => '70 12 34 56'])->assertStatus(409);
    }

    public function test_paiement_manuel_de_l_avance_controle_suspendu_et_complement(): void
    {
        $r = $this->reservee();
        $citoyen = $r->user;
        $this->actingAs($citoyen)->post(route('boutiques.avance.payer', $r->reference), ['canal' => 'VIREMENT'])->assertSessionHasNoErrors();
        $paiement = Paiement::query()->where('nature', Paiement::AVANCE)->sole();
        $this->assertNotNull($paiement->reference_versement);
        $this->actingAs($citoyen)->get(route('boutiques.avance.show', $r->reference))->assertOk()->assertSee('BF00 LOCATION')->assertSee($paiement->reference_versement);

        // Preuve jointe : délai suspendu, aucun passage en retard pendant le contrôle.
        $preuve = fn () => $this->actingAs($citoyen)->post(route('boutiques.avance.preuve', $r->reference), ['preuve' => UploadedFile::fake()->create('avis.pdf', 100, 'application/pdf')]);
        $preuve()->assertSessionHasNoErrors();
        $this->travel(31)->days();
        $this->artisan('raaga:relancer-paiements')->assertSuccessful();
        $this->assertNull($r->fresh()->avance_retard_le);

        // Incomplet : délai de complément (48 heures) sur la date limite de l'avance, sans clôture automatique ensuite.
        $this->en($this->controleur)->post(route('admin.paiements.controle', $paiement), ['controle' => Paiement::INCOMPLET, 'motif' => 'Il manque 35 000 FCFA',
            'controle_montant_recu' => 100_000])->assertSessionHas('succes');
        $this->assertEqualsWithDelta(48 * 3_600, now()->diffInSeconds($r->fresh()->avance_due_le), 5);
        $this->actingAs($citoyen)->get(route('boutiques.avance.show', $r->reference))->assertOk()->assertSee('data-incomplet', false);

        // Nouvelle preuve, reçue : l'avance est confirmée.
        $preuve()->assertSessionHasNoErrors();
        $this->en($this->controleur)->post(route('admin.paiements.controle', $paiement), ['controle' => Paiement::RECU, 'controle_reference' => 'REL-778'])->assertSessionHas('succes');
        $this->assertNotNull($r->fresh()->avance_payee_le);
        $this->assertSame(0, Remboursement::count());
    }

    public function test_plafonds_et_moyens_autorises_pour_la_location(): void
    {
        $r = $this->reservee();
        MoyenPaiement::parCode('ORANGE_MONEY')->forceFill(['usages' => [
            'ACTIONS' => ['autorise' => true, 'min' => null, 'max' => null], 'CAUTION' => ['autorise' => true, 'min' => null, 'max' => 100_000]]])->save();
        $this->assertSame(['VIREMENT'], array_map(fn ($m) => $m->code, $r->fresh()->canauxPaiement()));
        $this->actingAs($r->user)->post(route('boutiques.avance.payer', $r->reference), ['canal' => 'ORANGE_MONEY', 'telephone' => '70 12 34 56'])->assertSessionHasErrors('canal');
    }

    public function test_attribution_impossible_sans_avance_confirmee(): void
    {
        $r = $this->reservee();
        $attribuer = fn () => $this->en($this->validateur)->post(route('admin.boutiques.attribuer', [$r->campagne_boutiques_id, $r]), ['contrat_signe_le' => now()->toDateString()]);
        $page = $this->en($this->validateur)->get(route('admin.boutiques.show', $r->campagne_boutiques_id))->assertOk()->getContent();
        $this->assertStringNotContainsString('name="reference_avance"', $page);
        $attribuer()->assertSessionHasErrors(['contrat_signe_le' => 'Attribution impossible : l’avance n’est pas encore confirmée.']);

        // Avance en attente de contrôle : toujours refusée.
        $this->actingAs($r->user)->post(route('boutiques.avance.payer', $r->reference), ['canal' => 'VIREMENT']);
        $this->actingAs($r->user)->post(route('boutiques.avance.preuve', $r->reference), ['preuve' => UploadedFile::fake()->create('avis.pdf', 100, 'application/pdf')]);
        $attribuer()->assertSessionHasErrors('contrat_signe_le');

        // Confirmée : le VALIDATEUR renseigne la date du contrat ; la référence de l'avance vient du paiement.
        $paiement = Paiement::query()->where('nature', Paiement::AVANCE)->sole();
        $this->en($this->controleur)->post(route('admin.paiements.controle', $paiement), ['controle' => Paiement::RECU]);
        $this->en($this->validateur)->post(route('admin.boutiques.attribuer', [$r->campagne_boutiques_id, $r]), [])->assertSessionHasErrors('contrat_signe_le');
        $attribuer()->assertSessionHas('succes');
        $this->assertSame([StatutReservationBoutique::Attribuee, $paiement->reference, StatutBoutique::Attribuee],
            [$r->fresh()->statut, $r->fresh()->reference_avance, $r->boutique->fresh()->statut]);
    }

    public function test_relances_puis_passage_en_retard_sans_action_automatique(): void
    {
        Notification::fake();
        $r = $this->reservee();

        // 7 jours puis 24 heures avant la date limite, une seule fois chacune.
        $this->travelTo($r->avance_due_le->copy()->subDays(7)->addHour());
        $this->artisan('raaga:relancer-paiements')->assertSuccessful();
        $this->artisan('raaga:relancer-paiements')->assertSuccessful();
        $this->travelTo($r->avance_due_le->copy()->subHours(23));
        $this->artisan('raaga:relancer-paiements')->assertSuccessful();
        $this->assertSame([Relance::AVANCE_7J, Relance::AVANCE_24H], $r->relances()->orderBy('id')->pluck('type')->all());

        // Date limite dépassée : « avance en retard », citoyen notifié une fois ; rien d'autre ne change.
        $this->travelTo($r->avance_due_le->copy()->addMinute());
        $this->artisan('raaga:relancer-paiements')->assertSuccessful();
        $this->artisan('raaga:relancer-paiements')->assertSuccessful();
        $r->refresh();
        $this->assertNotNull($r->avance_retard_le);
        $this->assertSame([StatutReservationBoutique::Reservee, StatutBoutique::Reservee], [$r->statut, $r->boutique->fresh()->statut]);
        $this->assertSame([0, 0], [Remboursement::count(), DecisionAvance::count()]);
        Notification::assertSentToTimes($r->user, AvanceBoutiqueCitoyen::class, 1);
        $this->actingAs($r->user)->get(route('boutiques.suivi', $r->reference))->assertOk()->assertSee('Réservée : avance en retard')->assertSee('data-avance-retard', false);
        $this->en($this->regulateur)->get(route('admin.avances'))->assertOk()->assertSee($r->reference)->assertSee("135\u{00A0}000\u{00A0}FCFA");
    }

    public function test_paiement_tardif_accepte_avant_annulation(): void
    {
        $r = $this->enRetard();
        $this->proposer($r, ['decision' => DecisionAvance::ANNULER, 'retenue_pct' => 10])->assertSessionHas('succes');
        $d = DecisionAvance::sole();

        // En retard, l'avance se paie encore : la proposition devient caduque.
        $this->actingAs($r->user)->get(route('boutiques.avance.show', $r->reference))->assertOk()->assertSee('est dépassée', false);
        $this->actingAs($r->user)->post(route('boutiques.avance.payer', $r->reference), ['canal' => 'ORANGE_MONEY', 'telephone' => '70 12 34 56']);
        app(ServicePaiement::class)->confirmer(Paiement::query()->where('nature', Paiement::AVANCE)->sole()->reference, true);
        $r->refresh();
        $this->assertNotNull($r->avance_payee_le);
        $this->assertNull($r->avance_retard_le);
        $this->assertSame(DecisionAvance::CADUQUE, $d->fresh()->statut);
        $this->en($this->regulateur)->get(route('admin.avances'))->assertOk()->assertDontSee($r->reference);
        $this->en($this->validateur)->get(route('admin.a-valider.rubrique', 'avances'))->assertOk()->assertDontSee('avance:'.$d->id);
        $this->assertSame(StatutBoutique::Reservee, $r->boutique->fresh()->statut);
    }

    public function test_prolongation_confirmee(): void
    {
        Notification::fake();
        $r = $this->enRetard();
        $date = now()->addDays(10)->toDateString();

        $this->proposer($r, ['decision' => DecisionAvance::PROLONGER, 'nouvelle_date' => $date])->assertSessionHas('succes', 'Décision proposée au VALIDATEUR.');
        $d = DecisionAvance::sole();
        $this->assertSame(DecisionAvance::PROPOSEE, $d->statut);
        $this->en($this->regulateur)->get(route('admin.avances'))->assertOk()->assertSee('Décision en attente du VALIDATEUR');
        $this->en($this->validateur)->get(route('admin.a-valider.rubrique', ['rubrique' => 'avances', 'detail' => 'avance:'.$d->id]))->assertOk()
            ->assertSee('data-detail="avance:'.$d->id.'"', false)->assertSee($r->reference)->assertSee('Commerçant injoignable');

        $this->decider($d, 'confirmer')->assertSessionHas('succes');
        $r->refresh();
        $this->assertSame($date.' 23:59:59', $r->avance_due_le->format('Y-m-d H:i:s'));
        $this->assertNull($r->avance_retard_le);
        $this->assertSame(DecisionAvance::CONFIRMEE, $d->fresh()->statut);
        Notification::assertSentTo($r->user, AvanceBoutiqueCitoyen::class, fn ($n) => str_contains($n->message(), 'prolongé'));
        $this->en($this->regulateur)->get(route('admin.avances'))->assertOk()->assertDontSee($r->reference);
    }

    public function test_annulation_confirmee_avec_retenue_de_0_puis_10_pourcent(): void
    {
        Notification::fake();
        foreach ([[1, 0, 540_000], [2, 10, 486_000]] as [$numero, $pct, $rembourse]) {
            $r = $this->enRetard($numero);
            $this->proposer($r, ['decision' => DecisionAvance::ANNULER, 'retenue_pct' => $pct])->assertSessionHas('succes');
            $d = DecisionAvance::query()->where('reservation_boutique_id', $r->id)->sole();
            $this->decider($d, 'confirmer')->assertSessionHas('succes');

            $r->refresh();
            $this->assertSame([StatutReservationBoutique::Annulee, StatutBoutique::Disponible], [$r->statut, $r->boutique->fresh()->statut]);
            $remboursement = Remboursement::query()->where('reservation_boutique_id', $r->id)->sole();
            $this->assertSame([$rembourse, Remboursement::ANNULATION_AVANCE, Remboursement::A_EFFECTUER], [$remboursement->montant, $remboursement->origine, $remboursement->statut]);
            $this->assertSame($remboursement->id, $d->fresh()->remboursement_id);
            Notification::assertSentTo($r->user, AvanceBoutiqueCitoyen::class, fn ($n) => str_contains($n->message(), 'annulée'));
        }

        // Le CONTRÔLEUR DES PAIEMENTS suit les remboursements ouverts.
        $this->en($this->controleur)->get(route('admin.remboursements'))->assertOk()->assertSee('Réservation annulée faute d’avance', false)->assertSee("486\u{00A0}000\u{00A0}FCFA");
        // Boutique de nouveau réservable par un autre citoyen.
        $this->assertTrue(Boutiques::boutique('A', 1)->statut->reservable());
    }

    public function test_rejet_par_le_validateur_et_attendre(): void
    {
        Notification::fake();
        $r = $this->enRetard();

        // Motif obligatoire ; « attendre » est seulement noté, le dossier reste dans la liste.
        $this->proposer($r, ['decision' => DecisionAvance::ATTENDRE, 'motif' => ''])->assertSessionHasErrors('motif');
        $this->proposer($r, ['decision' => DecisionAvance::ATTENDRE])->assertSessionHas('succes', 'Décision notée : le dossier reste dans la liste.');
        $this->assertSame(DecisionAvance::NOTEE, DecisionAvance::sole()->statut);
        $this->en($this->validateur)->get(route('admin.a-valider.rubrique', 'avances'))->assertOk()->assertSee('Rien à valider dans cette rubrique.');

        $this->proposer($r, ['decision' => DecisionAvance::ANNULER, 'retenue_pct' => 120])->assertSessionHasErrors('retenue_pct');
        $this->proposer($r, ['decision' => DecisionAvance::PROLONGER, 'nouvelle_date' => now()->subDay()->toDateString()])->assertSessionHasErrors('nouvelle_date');
        $this->proposer($r, ['decision' => DecisionAvance::ANNULER, 'retenue_pct' => 50])->assertSessionHas('succes');
        $this->proposer($r, ['decision' => DecisionAvance::PROLONGER, 'nouvelle_date' => now()->addWeek()->toDateString()])->assertSessionHasErrors('decision');
        $d = DecisionAvance::query()->where('statut', DecisionAvance::PROPOSEE)->sole();

        // Rejet : motif obligatoire ; rien ne change, le RÉGULATEUR est notifié et peut proposer de nouveau.
        $this->decider($d, 'rejeter', 'Retenue trop élevée')->assertSessionHas('succes');
        $this->assertSame(DecisionAvance::REJETEE, $d->fresh()->statut);
        $this->assertSame([StatutReservationBoutique::Reservee, StatutBoutique::Reservee], [$r->fresh()->statut, $r->boutique->fresh()->statut]);
        $this->assertSame(0, Remboursement::count());
        Notification::assertSentTo($this->regulateur, DecisionRendue::class);
        Notification::assertNotSentTo($r->user, AvanceBoutiqueCitoyen::class, fn ($n) => str_contains($n->message(), 'annulée'));
        $this->en($this->regulateur)->get(route('admin.avances'))->assertOk()->assertSee($r->reference)->assertSee('Proposer une décision');
    }

    public function test_pas_d_auto_validation_et_droits_par_profil(): void
    {
        $r = $this->enRetard();
        $this->proposer($r, ['decision' => DecisionAvance::ANNULER, 'retenue_pct' => 10])->assertSessionHas('succes');
        $d = DecisionAvance::sole();

        // Auteur de la proposition : jamais décideur.
        try {
            app(AvancesBoutiques::class)->confirmer($d, $this->regulateur);
            $this->fail('Auto-validation acceptée.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('un autre VALIDATEUR', collect($e->errors())->flatten()->first());
        }
        $this->en($this->regulateur)->post(route('admin.avances.confirmer', $d))->assertForbidden();
        $this->en($this->validateur)->post(route('admin.avances.proposer', $r), ['decision' => DecisionAvance::ATTENDRE, 'motif' => 'Je propose aussi'])->assertForbidden();
        $this->assertSame(DecisionAvance::PROPOSEE, $d->fresh()->statut);

        // GESTIONNAIRE : statuts sans aucun montant ni nom, aucune action.
        $page = $this->en($this->gestionnaire)->get(route('admin.avances'))->assertOk()->assertSee($r->reference)->assertSee($r->boutique->code);
        $page->assertDontSee('FCFA')->assertDontSee($r->user->name)->assertDontSee('Proposer une décision');
        $this->en($this->gestionnaire)->post(route('admin.avances.proposer', $r), ['decision' => DecisionAvance::ATTENDRE, 'motif' => 'Essai du gestionnaire'])->assertForbidden();

        // VALIDATEUR : consulte la liste, décide seulement dans « À valider ».
        $this->en($this->validateur)->get(route('admin.avances'))->assertOk()->assertSee("540\u{00A0}000\u{00A0}FCFA")->assertDontSee('Proposer une décision');

        // Autres profils : refus.
        foreach ([$this->controleur, $this->superAdmin, User::factory()->role(Role::Admin)->create()] as $profil) {
            $this->en($profil)->get(route('admin.avances'))->assertForbidden();
        }
        $this->en($this->controleur)->post(route('admin.avances.rejeter', $d), ['motif' => 'Pas mon rôle'])->assertForbidden();
    }
}
