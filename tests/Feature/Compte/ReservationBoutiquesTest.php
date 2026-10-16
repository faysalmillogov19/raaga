<?php

namespace Tests\Feature\Compte;

use App\Enums\Role;
use App\Enums\StatutBoutique;
use App\Enums\StatutCampagneBoutiques;
use App\Enums\StatutKyc;
use App\Enums\StatutPaiement;
use App\Enums\StatutReservationBoutique;
use App\Models\BoutiqueStatut;
use App\Models\CampagneBoutiques;
use App\Models\MoyenPaiement;
use App\Models\Paiement;
use App\Models\Remboursement;
use App\Models\ReservationBoutique;
use App\Models\User;
use App\Models\VerificationKyc;
use App\Services\Boutiques\ReservationsBoutiques;
use App\Services\Paiement\ServicePaiement;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\Support\ActeursParametrage;
use Tests\Support\Boutiques;
use Tests\TestCase;

/**
 * Réservation des boutiques au premier arrivé : un seul gagnant par boutique (index unique partiel), limite par
 * personne sur le numéro de pièce, fenêtre d'ouverture, expiration (suspendue pendant le contrôle d'une preuve),
 * paiement de la caution qui réserve, attribution, plafonds des moyens, plan public sans donnée personnelle.
 */
class ReservationBoutiquesTest extends TestCase
{
    use ActeursParametrage, RefreshDatabase;

    private CampagneBoutiques $campagne;

    protected function setUp(): void
    {
        parent::setUp();

        $this->campagne = Boutiques::campagneOuverte();
    }

    private function citoyen(array $attributs = []): User
    {
        static $n = 0;
        $n++;

        return User::factory()->role(Role::Citoyen)->create(['statut_kyc' => StatutKyc::Valide, 'telephone' => '+2267000'.sprintf('%04d', $n), ...$attributs]);
    }

    public function test_parcours_plan_recapitulatif_et_pre_reservation(): void
    {
        $citoyen = $this->citoyen();
        $boutique = Boutiques::boutique('B');

        // Plan : site, niveau, bloc ; vrais codes, statuts en couleurs, légende ; une case disponible mène au récapitulatif.
        $this->get(route('boutiques', ['niveau' => 'RC', 'bloc' => 'B']))->assertOk()
            ->assertSee('data-plan', false)->assertSee('MCE-RC-B-001')->assertSee('data-statut="disponible"', false)
            ->assertSee('href="'.route('boutiques.boutique', $boutique->code).'"', false)
            ->assertSeeInOrder(['Disponible', 'Pré-réservée', 'Réservée', 'Attribuée']);

        // Récapitulatif : code, type, surface, loyer mensuel, caution (12 mois), avance (3 mois).
        $this->actingAs($citoyen)->get(route('boutiques.boutique', $boutique->code))->assertOk()
            ->assertSee('MCE-RC-B-001')->assertSee('Grande boutique')
            ->assertSee("120\u{00A0}000\u{00A0}FCFA")->assertSee("1\u{00A0}440\u{00A0}000\u{00A0}FCFA")->assertSee("360\u{00A0}000\u{00A0}FCFA");

        // Pièce obligatoire, engagements obligatoires.
        $this->actingAs($citoyen)->post(route('boutiques.reserver', $boutique->code), ['type_piece' => 'CNIB', 'numero_piece' => 'B1234567'])
            ->assertSessionHasErrors('piece');

        $reponse = Boutiques::reserver($this, $citoyen, $boutique)->assertSessionHasNoErrors();
        $r = ReservationBoutique::sole();
        $reponse->assertRedirect(route('boutiques.reservation.show', $r->reference));
        $this->assertSame(StatutReservationBoutique::PreReservee, $r->statut);
        $this->assertSame(['CNIB', 'B1234567'], [$r->type_piece, $r->numero_piece]);
        $this->assertSame([1_440_000, 360_000], [$r->caution, $r->avance]);
        $this->assertTrue($r->expire_le->equalTo(now()->addHours(72)) || $r->expire_le->diffInSeconds(now()->addHours(72), true) < 5);
        $this->assertSame(StatutBoutique::PreReservee, $boutique->fresh()->statut);
        $this->assertSame(1, $citoyen->kycDocuments()->count());

        // Pré-réservée à son nom avec compte à rebours ; le plan la montre pré-réservée, sans lien.
        $this->actingAs($citoyen)->get(route('boutiques.reservation.show', $r->reference))->assertOk()->assertSee('data-compte-rebours', false);
        $this->get(route('boutiques', ['niveau' => 'RC', 'bloc' => 'B']))->assertSee('data-statut="pre_reservee"', false)
            ->assertDontSee('href="'.route('boutiques.boutique', $boutique->code).'"', false);
    }

    public function test_reservation_simultanee_un_seul_gagnant(): void
    {
        $boutique = Boutiques::boutique('A');
        [$premier, $second] = [$this->citoyen(), $this->citoyen()];
        Boutiques::reserver($this, $premier, $boutique, 'B0000001')->assertSessionHasNoErrors();

        // Une requête concurrente a lu la boutique « disponible » avant l'enregistrement du premier : l'index unique
        // partiel refuse la seconde réservation active, la boutique reste au premier.
        $boutique->forceFill(['statut' => StatutBoutique::Disponible])->save();
        Boutiques::reserver($this, $second, $boutique, 'B0000002')
            ->assertSessionHasErrors(['boutique' => __('public.boutiques.erreurs.deja_prise')]);
        $this->assertSame([$premier->id], ReservationBoutique::query()->whereIn('statut', StatutReservationBoutique::actifs())->pluck('user_id')->all());

        // Garantie de la base elle-même, hors de toute logique applicative.
        $this->expectException(UniqueConstraintViolationException::class);
        ReservationBoutique::create(['reference' => 'BTQ-DOUBLON1', 'user_id' => $second->id, 'campagne_boutiques_id' => $this->campagne->id,
            'boutique_id' => $boutique->id, 'caution' => 1, 'statut' => StatutReservationBoutique::Reservee, 'expire_le' => now()->addDay()]);
    }

    public function test_limite_par_personne_sur_le_numero_de_piece(): void
    {
        $citoyen = $this->citoyen();
        Boutiques::reserver($this, $citoyen, Boutiques::boutique('A', 1), 'B 123-4567')->assertSessionHasNoErrors();

        // Même compte : refusé (une boutique par personne par défaut).
        Boutiques::reserver($this, $citoyen, Boutiques::boutique('A', 2), 'X9999999')
            ->assertSessionHasErrors(['boutique' => __('public.boutiques.erreurs.limite_type', ['n' => 1, 'type' => 'Petite boutique'])]);

        // Autre compte, même pièce (casse et séparateurs ignorés) : refusé aussi.
        Boutiques::reserver($this, $this->citoyen(), Boutiques::boutique('A', 2), 'b1234567')
            ->assertSessionHasErrors(['boutique' => __('public.boutiques.erreurs.limite_type', ['n' => 1, 'type' => 'Petite boutique'])]);

        // Autre pièce : accepté.
        Boutiques::reserver($this, $this->citoyen(), Boutiques::boutique('A', 2), 'C7654321')->assertSessionHasNoErrors();
        $this->assertSame(2, ReservationBoutique::count());

        // Limite relevée par la campagne pour ce type : deux petites boutiques pour la même personne.
        $petite = Boutiques::boutique('A', 3)->type_boutique_id;
        $this->campagne->forceFill(['limites' => [...$this->campagne->limites, $petite => 2]])->save();
        Boutiques::reserver($this, $citoyen, Boutiques::boutique('A', 3), 'B1234567')->assertSessionHasNoErrors();
        $this->assertSame(3, ReservationBoutique::count());
    }

    public function test_tentatives_limitees(): void
    {
        $citoyen = $this->citoyen(['telephone' => null]);
        foreach (range(1, 5) as $i) {
            Boutiques::reserver($this, $citoyen, Boutiques::boutique('A'))->assertSessionHasErrors(['boutique' => __('public.boutiques.erreurs.telephone')]);
        }
        Boutiques::reserver($this, $citoyen, Boutiques::boutique('A'))->assertStatus(429);
        $this->assertSame(0, ReservationBoutique::count());
    }

    public function test_ouverture_et_fermeture_de_la_campagne(): void
    {
        $citoyen = $this->citoyen();
        $fermee = ['boutique' => __('public.boutiques.erreurs.fermee')];

        // Avant l'heure d'ouverture : plan visible, aucune réservation possible.
        $this->campagne->forceFill(['date_ouverture' => now()->addHour()])->save();
        $this->get(route('boutiques'))->assertOk()->assertSee('data-campagne-fermee', false)
            ->assertDontSee('href="'.route('boutiques.boutique', Boutiques::boutique('A')->code).'"', false);
        Boutiques::reserver($this, $citoyen, Boutiques::boutique('A'))->assertSessionHasErrors($fermee);

        // Ouverte : accepté.
        $this->travel(61)->minutes();
        Boutiques::reserver($this, $citoyen, Boutiques::boutique('A'))->assertSessionHasNoErrors();

        // Après la fermeture, puis clôturée : refusé.
        $this->campagne->forceFill(['date_cloture' => now()->subMinute()])->save();
        Boutiques::reserver($this, $this->citoyen(), Boutiques::boutique('A', 2), 'C7654321')->assertSessionHasErrors($fermee);
        $this->campagne->forceFill(['date_cloture' => now()->addDay(), 'statut' => StatutCampagneBoutiques::Cloturee])->save();
        $this->actingAs($citoyen)->post(route('boutiques.reserver', Boutiques::boutique('A', 2)->code))->assertNotFound();
        $this->assertSame(1, ReservationBoutique::count());
    }

    public function test_expiration_apres_72_heures_et_historique(): void
    {
        $citoyen = $this->citoyen();
        $boutique = Boutiques::boutique('A');
        Boutiques::reserver($this, $citoyen, $boutique)->assertSessionHasNoErrors();
        $r = ReservationBoutique::sole();

        $this->travel(71)->hours();
        $this->artisan('raaga:expirer-reservations')->expectsOutputToContain('0 réservation(s) expirée(s).');
        $this->assertSame(StatutBoutique::PreReservee, $boutique->fresh()->statut);

        $this->travel(2)->hours();
        $this->artisan('raaga:expirer-reservations')->expectsOutputToContain('1 réservation(s) expirée(s).');
        $this->assertSame(StatutReservationBoutique::Expiree, $r->fresh()->statut);
        $this->assertSame(StatutBoutique::Disponible, $boutique->fresh()->statut);

        // Historique des statuts conservé, en ajout seul.
        $this->assertSame([['DISPONIBLE', 'PRE_RESERVEE', 'pre_reservation'], ['PRE_RESERVEE', 'DISPONIBLE', 'expiration']],
            BoutiqueStatut::query()->where('boutique_id', $boutique->id)->orderBy('id')->get()
                ->map(fn ($h) => [$h->ancien->value, $h->nouveau->value, $h->motif])->all());

        // De nouveau réservable, par n'importe qui.
        Boutiques::reserver($this, $this->citoyen(), $boutique, 'C7654321')->assertSessionHasNoErrors();
    }

    public function test_expiration_suspendue_pendant_le_controle_d_une_preuve(): void
    {
        $this->creerActeurs();
        config(['raaga.compte_location' => ['titulaire' => 'Raaga SA · location', 'banque' => 'Banque B', 'numero' => 'BF00 LOCATION']]);
        $citoyen = $this->citoyen();
        $boutique = Boutiques::boutique('A');
        Boutiques::reserver($this, $citoyen, $boutique)->assertSessionHasNoErrors();
        $r = ReservationBoutique::sole();

        $this->actingAs($citoyen)->post(route('boutiques.reservation.payer', $r->reference), ['canal' => 'VIREMENT'])->assertSessionHasNoErrors();
        $this->actingAs($citoyen)->post(route('boutiques.reservation.preuve', $r->reference), ['preuve' => UploadedFile::fake()->create('avis.pdf', 100, 'application/pdf')])
            ->assertSessionHasNoErrors();
        $paiement = Paiement::sole();
        $this->assertTrue($paiement->enControle());

        // Délai dépassé pendant le contrôle : ni expiration, ni libération.
        $this->travel(5)->days();
        app(ServicePaiement::class)->expirerReservations();
        $this->assertSame(StatutReservationBoutique::PreReservee, $r->fresh()->statut);
        $this->assertSame(StatutBoutique::PreReservee, $boutique->fresh()->statut);

        // Reçu par le contrôleur : la caution est payée, la boutique est réservée.
        $this->en(User::factory()->role(Role::ControleurPaiements)->create())->post(route('admin.paiements.controle', $paiement), ['controle' => 'RECU'])->assertSessionHas('succes');
        $this->assertSame(StatutReservationBoutique::Reservee, $r->fresh()->statut);
        $this->assertSame(StatutBoutique::Reservee, $boutique->fresh()->statut);
    }

    public function test_paiement_de_la_caution_puis_attribution(): void
    {
        $this->creerActeurs();
        $citoyen = $this->citoyen();
        $boutique = Boutiques::boutique('A');
        Boutiques::reserver($this, $citoyen, $boutique)->assertSessionHasNoErrors();
        $r = ReservationBoutique::sole();

        // Attribution impossible tant que la caution n'est pas payée.
        $attribuer = fn () => $this->en($this->validateur)->post(route('admin.boutiques.attribuer', [$this->campagne, $r]), ['contrat_signe_le' => now()->toDateString()]);
        $attribuer()->assertSessionHasErrors('contrat_signe_le');

        $this->actingAs($citoyen)->post(route('boutiques.reservation.payer', $r->reference), ['canal' => 'ORANGE_MONEY', 'telephone' => '70 12 34 56']);
        app(ServicePaiement::class)->confirmer(Paiement::sole()->reference, true);
        $this->assertSame([StatutReservationBoutique::Reservee, StatutBoutique::Reservee], [$r->fresh()->statut, $boutique->fresh()->statut]);
        $this->assertSame(540_000, Paiement::sole()->montant);

        // Avance pas encore confirmée : attribution toujours refusée.
        $attribuer()->assertSessionHasErrors(['contrat_signe_le' => 'Attribution impossible : l’avance n’est pas encore confirmée.']);
        $this->actingAs($citoyen)->post(route('boutiques.avance.payer', $r->reference), ['canal' => 'ORANGE_MONEY', 'telephone' => '70 12 34 56']);
        app(ServicePaiement::class)->confirmer(Paiement::query()->where('nature', Paiement::AVANCE)->sole()->reference, true);

        // Le RÉGULATEUR n'attribue pas ; le VALIDATEUR attribue (avance confirmée, contrat signé).
        $this->en($this->regulateur)->post(route('admin.boutiques.attribuer', [$this->campagne, $r]), ['contrat_signe_le' => now()->toDateString()])
            ->assertForbidden();
        $attribuer()->assertSessionHas('succes');
        $this->assertSame([StatutReservationBoutique::Attribuee, StatutBoutique::Attribuee], [$r->fresh()->statut, $boutique->fresh()->statut]);
        $this->assertSame(['disponible' => 4, 'pre_reservee' => 0, 'reservee' => 0, 'attribuee' => 1], $this->campagne->compteurs());

        // Tableau de bord du RÉGULATEUR : compteurs par campagne.
        $this->en($this->regulateur)->get(route('admin.tableau'))->assertOk()->assertSee('data-campagnes-boutiques', false)->assertSee('Réservation 2027');
    }

    public function test_plafonds_des_moyens_de_paiement(): void
    {
        $citoyen = $this->citoyen();
        Boutiques::reserver($this, $citoyen, Boutiques::boutique('B'))->assertSessionHasNoErrors();
        $r = ReservationBoutique::sole();

        // Caution de 1 440 000 FCFA : Orange Money plafonné à 1 000 000 n'est pas proposé.
        MoyenPaiement::parCode('ORANGE_MONEY')->forceFill(['usages' => [
            'ACTIONS' => ['autorise' => true, 'min' => null, 'max' => null], 'CAUTION' => ['autorise' => true, 'min' => null, 'max' => 1_000_000]]])->save();
        $this->assertSame(['VIREMENT'], array_map(fn ($m) => $m->code, $r->fresh()->canauxPaiement()));
        $this->actingAs($citoyen)->post(route('boutiques.reservation.payer', $r->reference), ['canal' => 'ORANGE_MONEY', 'telephone' => '70 12 34 56'])
            ->assertSessionHasErrors('canal');
        $this->assertSame(0, Paiement::count());
    }

    public function test_aucune_donnee_personnelle_sur_le_plan_public(): void
    {
        $citoyen = $this->citoyen(['name' => 'Awa OUEDRAOGO', 'email' => 'awa@example.bf']);
        Boutiques::reserver($this, $citoyen, Boutiques::boutique('A'), 'B7777777')->assertSessionHasNoErrors();
        $r = ReservationBoutique::sole();

        // Visiteur anonyme, puis autre citoyen connecté.
        $this->app['auth']->forgetGuards();
        $anonyme = $this->get(route('boutiques', ['niveau' => 'RC', 'bloc' => 'A']));
        foreach ([$anonyme, $this->actingAs($this->citoyen())->get(route('boutiques', ['niveau' => 'RC', 'bloc' => 'A']))] as $page) {
            $page->assertOk()->assertSee('data-statut="pre_reservee"', false);
            foreach (['Awa', 'OUEDRAOGO', 'awa@example.bf', 'B7777777', $citoyen->telephone, $r->reference] as $personnel) {
                $page->assertDontSee($personnel);
            }
        }

        // Le récapitulatif d'une boutique prise ne dit pas non plus qui l'a prise.
        $this->actingAs($this->citoyen())->get(route('boutiques.boutique', Boutiques::boutique('A')->code))->assertOk()->assertDontSee('OUEDRAOGO');
    }

    public function test_annulation_kyc_libere_la_boutique(): void
    {
        $citoyen = $this->citoyen();
        $boutique = Boutiques::boutique('A');
        Boutiques::reserver($this, $citoyen, $boutique)->assertSessionHasNoErrors();
        $r = ReservationBoutique::sole();
        $this->actingAs($citoyen)->post(route('boutiques.reservation.payer', $r->reference), ['canal' => 'ORANGE_MONEY', 'telephone' => '70 12 34 56']);
        app(ServicePaiement::class)->confirmer(Paiement::sole()->reference, true);

        $this->creerActeurs();
        $admin = User::factory()->role(Role::Admin)->create();
        $this->en($admin)->post(route('admin.kyc.douteux', $citoyen), ['motif' => 'Pièce retouchée'])->assertSessionHas('succes');
        $v = VerificationKyc::query()->sole();
        $this->en($this->validateur)->post(route('admin.a-valider.decider'), ['elements' => ['annulation:'.$v->id], 'decision' => 'confirmer', 'mode' => 'ligne'])
            ->assertSessionHas('succes');

        $this->assertSame(StatutReservationBoutique::Annulee, $r->fresh()->statut);
        $this->assertSame(StatutBoutique::Disponible, $boutique->fresh()->statut);
        $this->assertSame('annulation_identite', BoutiqueStatut::query()->where('boutique_id', $boutique->id)->latest('id')->value('motif'));
        $this->assertSame(540_000, (int) Remboursement::query()->where('reservation_boutique_id', $r->id)->value('montant'));
        $this->assertSame(StatutPaiement::Confirme, Paiement::sole()->statut);
        $this->assertTrue(ReservationsBoutiques::tarifer($boutique) !== null);
    }
}
