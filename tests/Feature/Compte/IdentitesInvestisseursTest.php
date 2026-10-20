<?php

namespace Tests\Feature\Compte;

use App\Contracts\SmsProvider;
use App\Enums\CanalSouscription;
use App\Enums\MethodeAllocation;
use App\Enums\ModeSaisie;
use App\Enums\PayeurFrais;
use App\Enums\PublicAutorise;
use App\Enums\RegleArret;
use App\Enums\RegleSiEchec;
use App\Enums\Role;
use App\Enums\StatutCarte;
use App\Enums\StatutKyc;
use App\Enums\StatutSouscription;
use App\Enums\StatutTranche;
use App\Enums\TypeCarte;
use App\Enums\TypeFraisSouscription;
use App\Models\Duree;
use App\Models\LigneRegistre;
use App\Models\Souscription;
use App\Models\Tranche;
use App\Models\User;
use App\Notifications\IdentiteInvestisseurCitoyen;
use App\Services\Cartes\Cartes;
use Database\Seeders\ParametresPaiementsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\Support\ActeursParametrage;
use Tests\Support\FakeSmsProvider;
use Tests\TestCase;

/**
 * Investisseurs : achat possible sans identité validée ; identité invalide signalée automatiquement, délai commun de
 * correction (48 heures par défaut), puis actions payées gelées (attestation et carte d'actionnaire bloquées,
 * dividendes toujours versés) ; dégel automatique à la validation de l'identité.
 */
class IdentitesInvestisseursTest extends TestCase
{
    use ActeursParametrage, RefreshDatabase;

    private FakeSmsProvider $sms;

    private Tranche $tranche;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sms = new FakeSmsProvider;
        $this->app->instance(SmsProvider::class, $this->sms);
        $this->creerActeurs();
        config(['raaga.phase.presentation_actions' => true]);
        $this->tranche = Tranche::create([
            'statut' => StatutTranche::Ouverte, 'nom' => 'Tranche 1', 'valeur_unitaire' => 10_000, 'min_actions_par_souscription' => 1,
            'objectif_montant' => 1_000_000_000, 'date_ouverture' => now()->subDay(), 'date_cloture' => now()->addDays(30),
            'publics_autorises' => [PublicAutorise::ResidentBf->value], 'mode_saisie' => ModeSaisie::NbActions, 'souscriptions_multiples' => true,
            'regle_arret' => RegleArret::ArretAObjectif, 'methode_allocation' => MethodeAllocation::Prorata, 'regle_si_echec' => RegleSiEchec::RemboursementIntegral,
            'delai_remboursement_jours' => 30, 'frais_paiement_supportes_par' => PayeurFrais::Raaga, 'frais_souscription_type' => TypeFraisSouscription::Aucun,
            'frais_souscription_valeur' => 0, 'canaux_actifs' => ['ORANGE_MONEY'], 'delai_paiement_valeur' => 48, 'delai_paiement_duree_id' => Duree::parCode('HEURE')->id,
            'delai_paiement_secondes' => 172_800, 'version' => 1, 'cree_par' => $this->regulateur->id,
        ]);
    }

    private function investisseur(StatutKyc $kyc, StatutSouscription $statut = StatutSouscription::Allouee, bool $registre = true): User
    {
        $u = User::factory()->role(Role::Citoyen)->create(['name' => 'Awa OUEDRAOGO', 'statut_kyc' => $kyc, 'telephone' => '+22670111111', 'email' => null]);
        Souscription::create(['reference' => 'RAA-B-'.$u->id.'X', 'user_id' => $u->id, 'tranche_id' => $this->tranche->id, 'nb_actions_demandees' => 4,
            'valeur_unitaire' => 10_000, 'montant' => 40_000, 'frais_souscription' => 0, 'canal' => CanalSouscription::Web, 'statut' => $statut, 'expire_le' => now()->addDay()]);
        if ($registre) {
            LigneRegistre::create(['numero_certificat' => 'CB-'.$u->id, 'user_id' => $u->id, 'tranche_id' => $this->tranche->id, 'nb_actions' => 4,
                'numero_action_debut' => 1, 'numero_action_fin' => 4, 'date_inscription' => now()]);
        }

        return $u->refresh();
    }

    public function test_identite_invalide_signalee_puis_corrigee_dans_le_delai(): void
    {
        Notification::fake();
        $awa = $this->investisseur(StatutKyc::Rejete);

        $this->artisan('raaga:expirer-reservations')->expectsOutputToContain('Investisseurs : 1 signalé(s)');
        $awa->refresh();
        $this->assertLessThan(5, abs($awa->kyc_actions_a_corriger_avant->diffInSeconds(now()->addHours(48))));
        Notification::assertSentTo($awa, IdentiteInvestisseurCitoyen::class);
        $this->assertTrue(collect($this->sms->envoyes)->contains(fn ($s) => str_contains($s['message'], 'dossier d’identité est invalide')));
        $this->actingAs($awa)->get(route('espace', ['onglet' => 'actions']))->assertOk()->assertSee('data-identite-a-corriger', false);
        // Signalé une seule fois.
        $this->artisan('raaga:expirer-reservations')->expectsOutputToContain('Investisseurs : 0 signalé(s)');

        // Pièces renvoyées avant la date limite : délai levé, jamais de gel.
        $awa->forceFill(['statut_kyc' => StatutKyc::EnAttente])->save();
        $this->artisan('raaga:expirer-reservations')->expectsOutputToContain('1 corrigé(s)');
        $this->travel(49)->hours();
        $this->artisan('raaga:expirer-reservations')->assertSuccessful();
        $this->assertFalse($awa->fresh()->actionsGelees());
    }

    public function test_non_corrigee_actions_gelees_puis_degelees_a_la_validation(): void
    {
        Notification::fake();
        $awa = $this->investisseur(StatutKyc::AMettreAJour);
        $this->artisan('raaga:expirer-reservations')->assertSuccessful();

        // Délai dépassé : gel ; attestation et carte d'actionnaire bloquées.
        $this->travel(48)->hours();
        $this->travel(1)->minute();
        $this->artisan('raaga:expirer-reservations')->expectsOutputToContain('1 gel(s)');
        $awa = $awa->fresh();
        $this->assertTrue($awa->actionsGelees());
        $this->actingAs($awa)->get(route('espace', ['onglet' => 'actions']))->assertOk()
            ->assertSee('data-actions-gelees', false)->assertSee('data-attestation-bloquee', false);
        $this->actingAs($awa)->get(route('espace.documents.attestation', 'CB-'.$awa->id))
            ->assertRedirect(route('espace', ['onglet' => 'actions']))->assertSessionHas('erreur', __('public.invest.identite.attestation_bloquee'));
        $this->assertNotSame(StatutCarte::Active, Cartes::courante($awa, TypeCarte::Actionnaire)?->statut);
        // Le dossier au back-office signale le gel.
        $this->en(User::factory()->role(Role::Admin)->create())->get(route('admin.kyc.dossier', $awa))->assertOk()->assertSee('data-actions-gelees', false);

        // Identité validée : dégel automatique, attestation et carte de nouveau disponibles.
        $awa->forceFill(['statut_kyc' => StatutKyc::Valide, 'piece_expire_le' => now()->addYears(3)])->save();
        $this->artisan('raaga:expirer-reservations')->expectsOutputToContain('1 dégel(s)');
        $awa->refresh();
        $this->assertFalse($awa->actionsGelees());
        $this->assertNull($awa->kyc_actions_a_corriger_avant);
        $this->assertSame(StatutCarte::Active, Cartes::courante($awa, TypeCarte::Actionnaire)?->statut);
        $this->actingAs($awa)->get(route('espace.documents.attestation', 'CB-'.$awa->id))->assertOk()->assertHeader('Content-Type', 'application/pdf');
        Notification::assertSentToTimes($awa, IdentiteInvestisseurCitoyen::class, 3);
    }

    public function test_rien_a_geler_sans_actions_payees(): void
    {
        $awa = $this->investisseur(StatutKyc::NonSoumis, StatutSouscription::Reservee, registre: false);
        $this->artisan('raaga:expirer-reservations')->expectsOutputToContain('1 signalé(s)');
        $this->travel(49)->hours();
        $this->artisan('raaga:expirer-reservations')->expectsOutputToContain('0 gel(s)');
        $this->assertFalse($awa->fresh()->actionsGelees());
    }

    public function test_delai_commun_lu_dans_les_delais_de_paiement(): void
    {
        // Valeurs initiales confirmées d'office (seeder) : 48 heures.
        $this->seed(ParametresPaiementsSeeder::class);
        $awa = $this->investisseur(StatutKyc::Rejete);
        $this->artisan('raaga:expirer-reservations')->assertSuccessful();
        $this->assertLessThan(5, abs($awa->fresh()->kyc_actions_a_corriger_avant->diffInSeconds(now()->addHours(48))));
    }
}
