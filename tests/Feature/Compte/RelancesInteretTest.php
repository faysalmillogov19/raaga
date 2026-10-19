<?php

namespace Tests\Feature\Compte;

use App\Contracts\SmsProvider;
use App\Enums\Role;
use App\Enums\StatutCampagneBoutiques;
use App\Enums\StatutKyc;
use App\Enums\StatutReservationBoutique;
use App\Models\Boutique;
use App\Models\CampagneBoutiques;
use App\Models\Duree;
use App\Models\InscriptionBoutique;
use App\Models\RelanceInteret;
use App\Models\ReservationBoutique;
use App\Models\User;
use App\Notifications\BoutiquesNonAttribueesRegulateur;
use App\Notifications\RelanceInteretCitoyen;
use App\Services\Boutiques\RelancesInteret;
use App\Services\Kyc\DossiersIdentite;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\Support\ActeursParametrage;
use Tests\Support\Boutiques;
use Tests\Support\FakeSmsProvider;
use Tests\TestCase;

/**
 * Liste d'attente générale (souscripteurs non attributaires) : boutique libérée sans personne à retenir, relance avec
 * le délai de la campagne, nouvelle inscription possible même après la clôture ; boutiques restées libres signalées au
 * RÉGULATEUR pour ouvrir une nouvelle campagne.
 */
class RelancesInteretTest extends TestCase
{
    use ActeursParametrage, RefreshDatabase;

    private FakeSmsProvider $sms;

    private CampagneBoutiques $campagne;

    private int $n = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sms = new FakeSmsProvider;
        $this->app->instance(SmsProvider::class, $this->sms);
        $this->creerActeurs();
        // Délai de relance : 2 jours.
        $this->campagne = Boutiques::campagneOuverte(['delai_relance_valeur' => 2, 'delai_relance_duree_id' => Duree::parCode('JOUR')->id, 'delai_relance_secondes' => 172_800]);
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

    private function desister(User $u, Boutique $b): void
    {
        $i = InscriptionBoutique::query()->where('user_id', $u->id)->where('boutique_id', $b->id)->latest('id')->firstOrFail();
        $this->actingAs($u)->post(route('boutiques.desister', $i))->assertSessionHasNoErrors();
    }

    private function clore(): void
    {
        $this->campagne->forceFill(['statut' => StatutCampagneBoutiques::Cloturee, 'date_cloture' => now()->subMinute(), 'cloturee_le' => now()])->save();
    }

    public function test_relance_apres_desistement_puis_nouvelle_inscription_apres_la_cloture(): void
    {
        Notification::fake();
        [$awa, $issa, $mariam, $paul] = [$this->citoyen(), $this->citoyen(), $this->citoyen(), $this->citoyen()];
        [$a1, $a2] = [Boutiques::boutique('A', 1), Boutiques::boutique('A', 2)];
        $this->inscrire($awa, $a1);
        $this->inscrire($issa, $a2);
        // Mariam s'inscrit sur A2 puis quitte la liste : non attributaire, elle est dans la liste générale.
        $this->inscrire($mariam, $a2);
        $this->desister($mariam, $a2);
        $this->clore();

        // Awa se désiste : personne sur la liste de A1, relance de la liste générale (Mariam, et Awa elle-même).
        $this->desister($awa, $a1);
        $this->assertEqualsCanonicalizing([$mariam->id, $awa->id], RelancesInteret::listeGenerale($this->campagne)->pluck('id')->all());
        $this->artisan('raaga:expirer-reservations')->expectsOutputToContain('1 ouverte(s)');
        $relance = RelanceInteret::sole();
        $this->assertSame([[$a1->id], 2], [$relance->boutiques, $relance->destinataires]);
        $this->assertLessThan(5, abs($relance->fin_le->diffInSeconds(now()->addDays(2))));
        Notification::assertSentTo($mariam, RelanceInteretCitoyen::class);
        Notification::assertNotSentTo($issa, RelanceInteretCitoyen::class);
        $this->assertTrue(collect($this->sms->envoyes)->contains(fn ($s) => str_contains($s['message'], 'de nouveau libre')));
        // Relancée une seule fois.
        $this->artisan('raaga:expirer-reservations')->expectsOutputToContain('0 ouverte(s)');

        // Campagne close : Paul (hors liste générale) ne peut pas s'inscrire ; Mariam si, et elle est retenue.
        $this->actingAs($paul)->get(route('boutiques.boutique', $a1->code))->assertNotFound();
        $this->actingAs($mariam)->get(route('boutiques', ['niveau' => 'RC', 'bloc' => 'A']))->assertOk()->assertSee('data-relance', false)
            ->assertSee('href="'.route('boutiques.boutique', $a1->code).'"', false);
        $this->actingAs($mariam)->get(route('boutiques.boutique', $a1->code))->assertOk()->assertSee('data-formulaire-reservation', false);
        $this->inscrire($mariam, $a1);
        $this->assertSame($mariam->id, ReservationBoutique::query()->where('boutique_id', $a1->id)->where('statut', StatutReservationBoutique::PreReservee)->sole()->user_id);

        // Fin du délai : A1 reprise, rien à signaler au RÉGULATEUR.
        $this->travel(2)->days();
        $this->travel(1)->minute();
        $this->artisan('raaga:expirer-reservations')->expectsOutputToContain('1 close(s)');
        $this->assertSame([], $relance->fresh()->restantes);
        Notification::assertNothingSentTo($this->regulateur);
    }

    public function test_boutiques_restees_libres_signalees_au_regulateur(): void
    {
        Notification::fake();
        [$awa, $mariam] = [$this->citoyen(), $this->citoyen()];
        $a1 = Boutiques::boutique('A', 1);
        $this->inscrire($awa, $a1);
        $this->inscrire($mariam, Boutiques::boutique('A', 2));
        $this->desister($mariam, Boutiques::boutique('A', 2));
        $this->clore();
        $this->desister($awa, $a1);
        $this->artisan('raaga:expirer-reservations')->assertSuccessful();

        // Personne ne reprend A1 dans le délai : le RÉGULATEUR est invité à ouvrir une nouvelle campagne.
        $this->travel(2)->days();
        $this->travel(1)->minute();
        $this->artisan('raaga:expirer-reservations')->expectsOutputToContain('1 close(s)');
        $relance = RelanceInteret::sole();
        // A2 aussi : Mariam l'avait obtenue (boutique libre) avant de s'en désister.
        $this->assertEqualsCanonicalizing([$a1->id, Boutiques::boutique('A', 2)->id], $relance->restantes);
        $this->assertNotNull($relance->regulateur_notifie_le);
        Notification::assertSentTo($this->regulateur, BoutiquesNonAttribueesRegulateur::class,
            fn ($n) => str_contains($n->toArray($this->regulateur)['message'], $a1->code));

        // Fiche de la campagne : listes d'attente et relances.
        $this->en($this->regulateur)->get(route('admin.boutiques.show', $this->campagne))->assertOk()
            ->assertSee('data-listes-attente', false)->assertSee('data-relances', false)->assertSee($a1->code);
    }

    public function test_campagne_close_sans_personne_a_relancer_regulateur_prevenu_aussitot(): void
    {
        Notification::fake();
        $awa = $this->citoyen();
        $a1 = Boutiques::boutique('A', 1);
        $this->inscrire($awa, $a1);
        $this->clore();
        // Identité d'Awa annulée (seule inscrite) : A1 libérée, liste générale vide.
        $v = app(DossiersIdentite::class)->douteux($awa, User::factory()->role(Role::Admin)->create(), 'Pièce falsifiée');
        app(DossiersIdentite::class)->confirmerAnnulation($v, $this->validateur);
        $this->assertTrue(RelancesInteret::listeGenerale($this->campagne)->isEmpty());

        $this->artisan('raaga:expirer-reservations')->assertSuccessful();
        $relance = RelanceInteret::sole();
        $this->assertSame([0, [$a1->id]], [$relance->destinataires, $relance->restantes]);
        $this->assertNotNull($relance->cloturee_le);
        Notification::assertSentTo($this->regulateur, BoutiquesNonAttribueesRegulateur::class);
    }

    public function test_campagne_ouverte_sans_personne_a_relancer_rien(): void
    {
        $awa = $this->citoyen();
        $this->inscrire($awa, Boutiques::boutique('A', 1));
        $v = app(DossiersIdentite::class)->douteux($awa, User::factory()->role(Role::Admin)->create(), 'Pièce falsifiée');
        app(DossiersIdentite::class)->confirmerAnnulation($v, $this->validateur);

        // Campagne encore ouverte : la boutique reste proposée à tous, aucune relance.
        $this->artisan('raaga:expirer-reservations')->assertSuccessful();
        $this->assertSame(0, RelanceInteret::query()->count());
    }
}
