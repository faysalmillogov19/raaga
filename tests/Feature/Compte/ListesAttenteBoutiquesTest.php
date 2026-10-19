<?php

namespace Tests\Feature\Compte;

use App\Contracts\SmsProvider;
use App\Enums\Role;
use App\Enums\StatutBoutique;
use App\Enums\StatutInscriptionBoutique;
use App\Enums\StatutKyc;
use App\Enums\StatutReservationBoutique;
use App\Models\Boutique;
use App\Models\InscriptionBoutique;
use App\Models\Remboursement;
use App\Models\ReservationBoutique;
use App\Models\User;
use App\Notifications\ListeAttenteBoutiqueCitoyen;
use App\Services\Boutiques\AvancesBoutiques;
use App\Services\Boutiques\ReservationsBoutiques;
use App\Services\Kyc\DossiersIdentite;
use App\Services\Paiement\ServicePaiement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\Support\ActeursParametrage;
use Tests\Support\Boutiques;
use Tests\Support\FakeSmsProvider;
use Tests\TestCase;

/**
 * Listes d'attente des boutiques, au premier arrivé, premier servi : inscription sur plusieurs boutiques, nombre de
 * personnes déjà inscrites, rang ; le suivant est retenu quand l'attributaire perd la boutique (caution ou avance non
 * payée, désistement, identité annulée), en sautant ceux qui sont déjà attributaires d'une boutique de ce type.
 */
class ListesAttenteBoutiquesTest extends TestCase
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
        Boutiques::campagneOuverte();
    }

    private function citoyen(): User
    {
        $this->n++;

        return User::factory()->role(Role::Citoyen)->create(['statut_kyc' => StatutKyc::Valide, 'telephone' => '+226700000'.sprintf('%02d', $this->n), 'email' => null]);
    }

    private function inscrire(User $u, Boutique $b): void
    {
        Boutiques::reserver($this, $u, $b, 'P'.sprintf('%07d', $u->id))->assertSessionHasNoErrors();
    }

    private function inscription(User $u, Boutique $b): InscriptionBoutique
    {
        return InscriptionBoutique::query()->where('user_id', $u->id)->where('boutique_id', $b->id)->latest('id')->firstOrFail();
    }

    private function reservation(Boutique $b): ?ReservationBoutique
    {
        return ReservationBoutique::query()->where('boutique_id', $b->id)->whereIn('statut', StatutReservationBoutique::actifs())->first();
    }

    private function payerCaution(ReservationBoutique $r): void
    {
        $this->actingAs($r->user)->post(route('boutiques.reservation.payer', $r->reference), ['canal' => 'ORANGE_MONEY', 'telephone' => '70 12 34 56']);
        app(ServicePaiement::class)->confirmer($r->paiements()->latest('id')->value('reference'), true);
    }

    public function test_plusieurs_inscriptions_nombre_d_inscrits_et_rang(): void
    {
        [$awa, $issa, $mariam] = [$this->citoyen(), $this->citoyen(), $this->citoyen()];
        $a1 = Boutiques::boutique('A', 1);

        // Personne avant elle : la boutique libre est pré-réservée à son nom.
        $this->actingAs($awa)->get(route('boutiques.boutique', $a1->code))->assertOk()
            ->assertSee('Personne ne s’est encore inscrit sur cette boutique.', false)->assertSee(__('public.boutiques.attente.bouton_reserver'));
        $this->inscrire($awa, $a1);
        $this->assertSame($awa->id, $this->reservation($a1)->user_id);

        // Boutique pré-réservée : le suivant voit combien sont inscrits et s'inscrit en liste d'attente, rang 1.
        $this->actingAs($issa)->get(route('boutiques.boutique', $a1->code))->assertOk()
            ->assertSee('1 personne s’est déjà inscrite sur cette boutique.', false)->assertSee('data-liste-attente', false)
            ->assertSee(__('public.boutiques.attente.bouton_inscrire'));
        Boutiques::reserver($this, $issa, $a1, 'P0000002')->assertSessionHasNoErrors()
            ->assertRedirect(route('espace', ['onglet' => 'boutiques']))->assertSessionHas('succes', __('public.boutiques.attente.inscrit', ['code' => $a1->code, 'rang' => 1]));
        $this->inscrire($mariam, $a1);
        $this->assertSame([1, 2], [$this->inscription($issa, $a1)->rang(), $this->inscription($mariam, $a1)->rang()]);
        $this->actingAs($this->citoyen())->get(route('boutiques.boutique', $a1->code))->assertSee('3 personnes se sont déjà inscrites sur cette boutique.', false);

        // Plusieurs boutiques : Issa s'inscrit aussi sur A2, libre, et en devient l'attributaire ; sa pièce est reprise.
        $this->actingAs($issa)->get(route('boutiques.boutique', Boutiques::boutique('A', 2)->code))->assertSee('data-piece-fournie', false)->assertDontSee('name="piece"', false);
        $this->actingAs($issa)->post(route('boutiques.reserver', Boutiques::boutique('A', 2)->code), [
            'engagements' => array_map(fn ($d) => $d->value, ReservationsBoutiques::ENGAGEMENTS),
        ])->assertSessionHasNoErrors();
        $this->assertSame($issa->id, $this->reservation(Boutiques::boutique('A', 2))->user_id);

        // Déjà inscrit : refusé ; « Mon espace » montre les inscriptions en attente et leur rang.
        Boutiques::reserver($this, $mariam, $a1)->assertSessionHasErrors(['boutique' => __('public.boutiques.erreurs.deja_inscrit')]);
        $this->actingAs($mariam)->get(route('espace', ['onglet' => 'boutiques']))->assertOk()
            ->assertSee('Mes inscriptions en liste d’attente', false)->assertSee('data-inscription="'.$a1->code.'"', false)->assertSee('data-desistement', false);
    }

    public function test_caution_non_payee_le_suivant_non_attributaire_est_retenu(): void
    {
        Notification::fake();
        [$awa, $issa, $mariam] = [$this->citoyen(), $this->citoyen(), $this->citoyen()];
        [$a1, $a2] = [Boutiques::boutique('A', 1), Boutiques::boutique('A', 2)];
        $this->inscrire($awa, $a1);
        $this->inscrire($issa, $a2);
        // Issa (attributaire de A2, même type) puis Mariam s'inscrivent sur A1.
        $this->inscrire($issa, $a1);
        $this->inscrire($mariam, $a1);

        // Awa ne paie pas la caution : Issa, déjà attributaire d'une petite boutique, est sauté ; Mariam est retenue.
        $this->travel(73)->hours();
        $this->artisan('raaga:expirer-reservations')->expectsOutputToContain('attributaire(s) retenu(s)');
        $retenue = $this->reservation($a1);
        $this->assertSame($mariam->id, $retenue->user_id);
        $this->assertSame(StatutReservationBoutique::PreReservee, $retenue->statut);
        $this->assertTrue($retenue->expire_le->isFuture());
        $this->assertSame(StatutInscriptionBoutique::Retenue, $this->inscription($mariam, $a1)->statut);
        $this->assertSame(StatutInscriptionBoutique::Perdue, $this->inscription($awa, $a1)->statut);
        $this->assertSame(StatutInscriptionBoutique::EnAttente, $this->inscription($issa, $a1)->statut);
        Notification::assertSentTo($mariam, ListeAttenteBoutiqueCitoyen::class);
        $this->assertTrue(collect($this->sms->envoyes)->contains(fn ($s) => str_contains($s['message'], $a1->code) && str_contains($s['message'], 'liste d’attente')));
    }

    public function test_desistement_en_attente_puis_de_la_boutique_retenue_caution_payee(): void
    {
        [$awa, $issa, $mariam] = [$this->citoyen(), $this->citoyen(), $this->citoyen()];
        $a1 = Boutiques::boutique('A', 1);
        $this->inscrire($awa, $a1);
        $this->inscrire($issa, $a1);
        $this->inscrire($mariam, $a1);

        // Issa quitte la liste : Mariam passe au rang 1.
        $this->actingAs($issa)->post(route('boutiques.desister', $this->inscription($issa, $a1)))->assertRedirect(route('espace', ['onglet' => 'boutiques']));
        $this->assertSame(StatutInscriptionBoutique::Desistee, $this->inscription($issa, $a1)->statut);
        $this->assertSame(1, $this->inscription($mariam, $a1)->rang());
        // Personne d'autre ne désiste pour lui.
        $this->actingAs($issa)->post(route('boutiques.desister', $this->inscription($mariam, $a1)))->assertForbidden();

        // Awa paie la caution puis se désiste : réservation annulée, caution en attente de retenue, Mariam retenue.
        $r = $this->reservation($a1);
        $this->payerCaution($r);
        $this->actingAs($awa)->post(route('boutiques.desister', $this->inscription($awa, $a1)))->assertSessionHasNoErrors();
        $this->assertSame([StatutReservationBoutique::Annulee, ReservationBoutique::ANNULATION_DESISTEMENT], [$r->fresh()->statut, $r->fresh()->annulation_motif]);
        $rb = Remboursement::query()->where('reservation_boutique_id', $r->id)->sole();
        $this->assertSame([Remboursement::DESISTEMENT, Remboursement::EN_ATTENTE_RETENUE], [$rb->origine, $rb->statut]);
        $this->assertSame($mariam->id, $this->reservation($a1)->user_id);
        // Caution à décider dans « Cautions à rembourser » (RÉGULATEUR).
        $this->en($this->regulateur)->get(route('admin.cautions'))->assertOk()->assertSee($r->reference);
    }

    public function test_avance_non_payee_et_identite_annulee_liberent_pour_le_suivant(): void
    {
        [$awa, $issa, $mariam] = [$this->citoyen(), $this->citoyen(), $this->citoyen()];
        $a1 = Boutiques::boutique('A', 1);
        $this->inscrire($awa, $a1);
        $this->inscrire($issa, $a1);
        $this->inscrire($mariam, $a1);

        // Avance non payée à la date limite : annulation automatique, Issa retenu.
        $r = $this->reservation($a1);
        $this->payerCaution($r);
        $r->refresh()->forceFill(['avance_due_le' => now()->subMinute()])->save();
        app(AvancesBoutiques::class)->annulerEchues();
        $this->assertSame($issa->id, $this->reservation($a1)->user_id);

        // Identité d'Issa annulée (dossier douteux confirmé) : ses inscriptions sont closes, Mariam est retenue.
        $v = app(DossiersIdentite::class)->douteux($issa, User::factory()->role(Role::Admin)->create(), 'Pièce falsifiée');
        app(DossiersIdentite::class)->confirmerAnnulation($v, $this->validateur);
        $this->assertSame(StatutInscriptionBoutique::Annulee, $this->inscription($issa, $a1)->statut);
        $this->assertSame($mariam->id, $this->reservation($a1)->user_id);
    }

    public function test_boutique_attribuee_ferme_la_liste(): void
    {
        $a1 = Boutiques::boutique('A', 1);
        $a1->forceFill(['statut' => StatutBoutique::Attribuee])->save();

        $this->actingAs($this->citoyen())->get(route('boutiques.boutique', $a1->code))->assertOk()->assertSee('data-indisponible', false)
            ->assertDontSee('data-formulaire-reservation', false);
        Boutiques::reserver($this, $this->citoyen(), $a1)->assertSessionHasErrors(['boutique' => __('public.boutiques.erreurs.deja_attribuee')]);
        $this->get(route('boutiques', ['niveau' => 'RC', 'bloc' => 'A']))->assertDontSee('href="'.route('boutiques.boutique', $a1->code).'"', false);
    }
}
