<?php

namespace Tests\Feature\Boutiques;

use App\Contracts\SmsProvider;
use App\Enums\Role;
use App\Enums\StatutCarte;
use App\Enums\StatutKyc;
use App\Enums\TypeCarte;
use App\Enums\TypeTitulaire;
use App\Models\InscriptionBoutique;
use App\Models\KycDocument;
use App\Models\Recepisse;
use App\Models\ReservationBoutique;
use App\Models\User;
use App\Services\Boutiques\Formalisation;
use App\Services\Boutiques\ReservationsBoutiques;
use App\Services\Cartes\Cartes;
use App\Services\Plateforme\ParametresPlateforme;
use App\Support\ATraiter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Support\Boutiques;
use Tests\Support\ConnexionBackoffice;
use Tests\Support\FakeSmsProvider;
use Tests\TestCase;

/**
 * Formalisation différée (réunion du 05/11/2026, lot 2) : réservation sans IFU pour une personne physique, dossier
 * « en cours de formalisation », récépissé de préinscription (numéro propre, QR code, PDF dans Mon espace et au guichet,
 * vérification publique sans donnée personnelle), délai réglable (6 mois par défaut), rappels à 2 mois, 1 mois et
 * 15 jours, alerte au back-office à l'échéance sans action sur la boutique ; carte seulement avec un IFU validé.
 */
class FormalisationTest extends TestCase
{
    use ConnexionBackoffice, RefreshDatabase;

    private FakeSmsProvider $sms;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sms = new FakeSmsProvider;
        $this->app->instance(SmsProvider::class, $this->sms);
        Storage::fake('local');
        Boutiques::campagneOuverte();
    }

    private function sansIfu(): User
    {
        return User::factory()->role(Role::Citoyen)->create(['statut_kyc' => StatutKyc::Valide, 'telephone' => '+22670009999', 'ifu' => null, 'nom' => 'SANOU', 'prenoms' => 'Ali']);
    }

    private function reserver(User $user, string $bloc = 'A', int $numero = 1): void
    {
        $this->actingAs($user)->post(route('boutiques.reserver', Boutiques::boutique($bloc, $numero)->code), [
            'type_piece' => 'CNIB', 'numero_piece' => Boutiques::nip('B'.sprintf('%07d', $user->id)), 'piece' => UploadedFile::fake()->image('piece.jpg'),
            ...Boutiques::donneesCommercant(),
        ])->assertSessionHasNoErrors();
    }

    public function test_reservation_sans_ifu_recepisse_et_dossier_en_cours_de_formalisation(): void
    {
        $user = $this->sansIfu();
        $this->actingAs($user)->get(route('boutiques.boutique', Boutiques::boutique('A', 1)->code))->assertOk()
            ->assertSee('data-sans-ifu', false)->assertSee('récépissé de préinscription Raaga', false)->assertSee('6 mois');
        $this->reserver($user);

        $user->refresh();
        $this->assertTrue(Formalisation::enCours($user));
        $this->assertSame(now()->addMonthsNoOverflow(6)->toDateString(), $user->formalisation_echeance_le->toDateString());
        $recepisse = Recepisse::query()->where('user_id', $user->id)->sole();
        $this->assertMatchesRegularExpression('/^RP-'.now()->format('Y').'-000001$/', $recepisse->numero);
        $this->assertSame(InscriptionBoutique::query()->where('user_id', $user->id)->value('id'), $recepisse->inscription_boutique_id);

        // Deuxième inscription : nouveau récépissé, même échéance (le délai n'est jamais repoussé).
        $this->travel(10)->days();
        $this->reserver($user->fresh(), 'B', 1);
        $this->assertSame(2, Recepisse::query()->where('user_id', $user->id)->count());
        $this->assertSame($recepisse->echeance_le->toDateString(), $user->fresh()->formalisation_echeance_le->toDateString());

        // Mon espace : état et téléchargement ; PDF du titulaire seulement.
        $this->actingAs($user->fresh())->get(route('espace', ['onglet' => 'boutiques']))->assertOk()->assertSee('data-formalisation', false)
            ->assertSee('Dossier en cours de formalisation')->assertSee(route('espace.recepisse', $recepisse->numero), false);
        $pdf = $this->actingAs($user->fresh())->get(route('espace.recepisse', $recepisse->numero))->assertOk();
        $this->assertSame('application/pdf', $pdf->headers->get('Content-Type'));
        $this->assertStringStartsWith('%PDF', $pdf->getContent());
        $autre = User::factory()->role(Role::Citoyen)->create();
        $this->actingAs($autre)->get(route('espace.recepisse', $recepisse->numero))->assertForbidden();
    }

    public function test_verification_publique_sans_donnee_personnelle_et_impression_au_guichet(): void
    {
        $user = $this->sansIfu();
        $this->reserver($user);
        $recepisse = Recepisse::query()->sole();

        $this->app['auth']->forgetGuards();
        $this->get(route('recepisses.verifier', $recepisse->jeton))->assertOk()->assertSee('Récépissé authentique', false)
            ->assertSee($recepisse->numero)->assertSee('sa formalisation est en cours', false)->assertDontSee('SANOU')->assertDontSee('Ali');
        $this->get(route('recepisses.verifier', str_repeat('x', 64)))->assertNotFound();

        $this->connecterBackoffice(Role::AgentGuichet);
        // Recherche par le numéro du récépissé : dossier du titulaire, même sans carte.
        $this->post(route('admin.guichet.rechercher'), ['carte' => strtolower($recepisse->numero)])->assertRedirect(route('admin.guichet.titulaire', $user));
        $this->get(route('admin.guichet.titulaire', $user))->assertOk()->assertSee('data-recepisses', false)->assertSee($recepisse->numero);
        $this->assertSame('application/pdf', $this->get(route('admin.guichet.recepisse', $recepisse->numero))->assertOk()->headers->get('Content-Type'));
    }

    public function test_rappels_alerte_a_l_echeance_sans_action_sur_la_boutique_et_delai_reglable(): void
    {
        app(ParametresPlateforme::class)->enregistrer('carte', [...app(ParametresPlateforme::class)->section('carte'), 'delaiFormalisationMois' => 3], null);
        $user = $this->sansIfu();
        $this->reserver($user);
        $user->refresh();
        $echeance = $user->formalisation_echeance_le;
        $this->assertSame(now()->addMonthsNoOverflow(3)->toDateString(), $echeance->toDateString());
        $formalisation = app(Formalisation::class);

        // À 2 mois de l'échéance : un rappel, une seule fois.
        $this->travelTo($echeance->copy()->subMonthsNoOverflow(2));
        $this->assertSame(1, $formalisation->echeances()['rappels']);
        $this->assertSame(0, $formalisation->echeances()['rappels']);
        $this->assertStringContainsString('Déposez votre IFU et son attestation avant le '.$echeance->format('d/m/Y'), end($this->sms->envoyes)['message']);
        // À 1 mois, puis à 15 jours.
        $this->travelTo($echeance->copy()->subMonthsNoOverflow(1));
        $this->assertSame(1, $formalisation->echeances()['rappels']);
        $this->travelTo($echeance->copy()->subDays(15));
        $this->assertSame(1, $formalisation->echeances()['rappels']);

        // Échéance dépassée : alerte au back-office (ADMIN), la réservation n'est pas touchée.
        $statutAvant = ReservationBoutique::query()->where('user_id', $user->id)->value('statut');
        $this->travelTo($echeance->copy()->addDay());
        $this->assertSame(1, $formalisation->echeances()['alertes']);
        $this->assertTrue(Formalisation::echue($user->fresh()));
        $this->assertSame($statutAvant, ReservationBoutique::query()->where('user_id', $user->id)->value('statut'));
        $admin = $this->connecterBackoffice(Role::Admin);
        $this->assertNotEmpty(collect(ATraiter::pour($admin))->where('element', __('admin.tableau.a_traiter.elements.formalisation_echue')));
    }

    public function test_carte_deja_emise_avec_un_ifu_non_valide_reste_active_et_signalee_a_l_admin(): void
    {
        // Carte émise (IFU validé), puis attestation remise « en attente », comme pour une carte émise avant la règle.
        $user = User::factory()->role(Role::Citoyen)->avecIfu()->create(['statut_kyc' => StatutKyc::Valide, 'piece_expire_le' => now()->addYears(5)->toDateString()]);
        $r = ReservationBoutique::create(['reference' => 'BTQ-FORMAL01', 'user_id' => $user->id, 'campagne_boutiques_id' => Boutiques::campagneOuverte()->id,
            'boutique_id' => Boutiques::boutique('A', 1)->id, 'caution' => 540_000, 'avance' => 135_000, 'mois_avance' => 3, 'statut' => 'RESERVEE',
            'expire_le' => now(), 'avance_payee_le' => now(), 'reference_avance' => 'PAY-F01']);
        app(ReservationsBoutiques::class)->attribuer($r, now()->toDateString(), User::factory()->role(Role::Validateur)->create());
        $carte = Cartes::courante($user, TypeCarte::Commercant);
        $this->assertSame([StatutCarte::Active, $user->ifu], [$carte->statut, $carte->numero]);
        KycDocument::query()->where('user_id', $user->id)->where('type', 'IFU')->update(['statut' => 'EN_ATTENTE']);

        // Recalcul (nouvelle personne à prévenir) : la carte reste active, avec son numéro, sans repasser en attente.
        $user->forceFill(['personne_prevenir_nom' => 'KABORE Issa'])->save();
        $mise = app(Cartes::class)->synchroniser($user->fresh(), TypeCarte::Commercant);
        $this->assertSame([StatutCarte::Active, $user->ifu], [$mise->statut, $mise->numero]);
        // « À traiter » de l'ADMIN : IFU à valider pour des cartes déjà émises.
        $this->assertSame([$user->id], Cartes::ifuAValider()->pluck('id')->all());
        $admin = $this->connecterBackoffice(Role::Admin);
        $this->assertNotEmpty(collect(ATraiter::pour($admin))->where('element', __('admin.tableau.a_traiter.elements.ifu_carte_emise')));

        // Réémission après perte : nouvelle émission, IFU validé exigé (en attente, sans numéro).
        $nouvelle = app(Cartes::class)->reemettre($user->fresh(), TypeCarte::Commercant, $admin);
        $this->assertSame([StatutCarte::EnAttente, null], [$nouvelle->statut, $nouvelle->numero]);
    }

    public function test_ifu_valide_formalisation_achevee_et_personne_morale_toujours_avec_ifu(): void
    {
        $user = $this->sansIfu();
        $this->reserver($user);
        $this->assertTrue(Formalisation::enCours($user->fresh()));
        // IFU saisi sans attestation validée : toujours en cours ; validé : formalisé.
        $user->forceFill(['ifu' => '00077777A'])->save();
        $this->assertTrue(Formalisation::enCours($user->fresh()));
        Boutiques::ifuVerifie($user->fresh());
        $this->assertTrue(Formalisation::formalise($user->fresh()));
        $this->assertFalse(Formalisation::enCours($user->fresh()));

        $morale = User::factory()->role(Role::Citoyen)->create(['type_titulaire' => TypeTitulaire::Morale, 'raison_sociale' => 'Société Y', 'forme_morale' => 'ENTREPRISE',
            'ifu' => null, 'statut_kyc' => StatutKyc::Valide]);
        $this->actingAs($morale)->post(route('boutiques.reserver', Boutiques::boutique('A', 3)->code), [
            'type_piece' => 'IFU', 'numero_piece' => '', ...Boutiques::donneesCommercant()])->assertSessionHasErrors();
        $this->assertSame(0, Recepisse::query()->where('user_id', $morale->id)->count());
    }
}
